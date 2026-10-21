<?php

namespace App\Services\Leads;

use App\Enums\Locale;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\User;
use App\Support\CustomerNameNormalizer;
use Illuminate\Support\Collection;

/**
 * Finds the organizations a lead may already be (Spec 04 section 18.3) and the field-by-field
 * difference between the lead and an existing organization, so "Convert to customer" never
 * creates a duplicate and never overwrites organization data silently.
 *
 *  - same tax ID (any role, archived included): creating a new customer is blocked;
 *  - same e-mail or phone (organization or one of its contacts) or the same name: a warning; the
 *    manager links, adds the customer role, or confirms that it is a different company.
 */
class LeadConversionMatcher
{
    /** Partner fields the lead can fill or change, in display order. */
    public const DIFF_FIELDS = ['name', 'tax_id', 'industry', 'email', 'phone', 'preferred_locale', 'owner_id'];

    public const MAX_MATCHES = 5;

    /**
     * @return Collection<int, array{organization: Partner, reasons: list<string>, action: string}> action link|add_role|unavailable
     */
    public function matches(Lead $lead): Collection
    {
        /** @var array<int, list<string>> $reasons */
        $reasons = [];
        $add = function (iterable $ids, string $reason) use (&$reasons): void {
            foreach ($ids as $id) {
                if ($id !== null && ! in_array($reason, $reasons[(int) $id] ?? [], true)) {
                    $reasons[(int) $id][] = $reason;
                }
            }
        };

        if ($lead->customer_id !== null) {
            $add([$lead->customer_id], 'linked');
        }

        $taxId = Customer::normalizeTaxId($lead->tax_id);

        if ($taxId !== null) {
            $add(Partner::withTrashed()->where('tax_id', $taxId)->limit(self::MAX_MATCHES)->pluck('id'), 'tax_id');
        }

        $email = filled($lead->contact_email) ? mb_strtolower(trim((string) $lead->contact_email)) : null;

        if ($email !== null) {
            $add(Partner::query()->where('email', $email)->limit(self::MAX_MATCHES)->pluck('id'), 'email');
            $add(Contact::query()->where('email', $email)->limit(self::MAX_MATCHES)->pluck('partner_id'), 'email');
        }

        $phone = Customer::normalizePhone($lead->contact_phone);

        if ($phone !== null && strlen($phone) >= 7) {
            $add(Partner::query()->where('phone', $phone)->limit(self::MAX_MATCHES)->pluck('id'), 'phone');
            $add(Contact::query()->where('phone', $phone)->limit(self::MAX_MATCHES)->pluck('partner_id'), 'phone');
        }

        $name = CustomerNameNormalizer::normalize($lead->company_name);

        if (mb_strlen($name) >= 3) {
            $add(Partner::query()->where('name_normalized', $name)->limit(self::MAX_MATCHES)->pluck('id'), 'name');
        }

        if ($reasons === []) {
            return collect();
        }

        $rank = fn (array $list): int => in_array('linked', $list, true) ? 0 : (in_array('tax_id', $list, true) ? 1 : 2);

        return Partner::withTrashed()->with('owner:id,name')->whereKey(array_keys($reasons))->get()
            ->map(fn (Partner $organization): array => [
                'organization' => $organization,
                'reasons' => $reasons[$organization->id],
                'action' => $this->actionFor($organization),
            ])
            ->sortBy(fn (array $match): string => $rank($match['reasons']).'-'.str_pad((string) $match['organization']->id, 10, '0', STR_PAD_LEFT))
            ->take(self::MAX_MATCHES)->values();
    }

    /** What the manager may do with a matched organization. */
    public function actionFor(Partner $organization): string
    {
        return match (true) {
            $organization->trashed() || $organization->isDeletedInOneC() => 'unavailable',
            (bool) $organization->is_customer => 'link',
            default => 'add_role',
        };
    }

    /** The organization holding the lead's tax ID (blocks creating a new customer). */
    public function taxIdMatch(Lead $lead): ?Partner
    {
        $taxId = Customer::normalizeTaxId($lead->tax_id);

        return $taxId === null ? null : Partner::withTrashed()->where('tax_id', $taxId)->orderBy('id')->first();
    }

    /**
     * Lead values mapped onto organization fields (normalized like the organization stores them).
     *
     * @return array<string, string|int|null>
     */
    public function leadValues(Lead $lead): array
    {
        return [
            'name' => filled($lead->company_name) ? trim((string) $lead->company_name) : null,
            'tax_id' => Customer::normalizeTaxId($lead->tax_id),
            'industry' => filled($lead->industry) ? trim((string) $lead->industry) : null,
            'email' => filled($lead->contact_email) ? mb_strtolower(trim((string) $lead->contact_email)) : null,
            'phone' => Customer::normalizePhone($lead->contact_phone),
            'preferred_locale' => Locale::tryFrom((string) $lead->locale)?->value,
            'owner_id' => $lead->owner_id,
        ];
    }

    /**
     * Fields where the lead differs from the organization. Empty organization fields default to
     * the lead value; filled ones default to "keep". The tax ID is offered only when the
     * organization has none and is not linked to 1C (it is never changed otherwise).
     *
     * @return list<array{field: string, lead: string, current: string, default: string}>
     */
    public function diff(Lead $lead, Partner $organization): array
    {
        $rows = [];

        foreach ($this->leadValues($lead) as $field => $value) {
            $current = $organization->getAttribute($field);
            $current = $current instanceof \BackedEnum ? $current->value : $current;

            if ($value === null || $value === '' || (string) $value === (string) $current) {
                continue;
            }

            if ($field === 'tax_id' && (filled($current) || $organization->isLinkedToOneC())) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'lead' => $this->display($field, $value),
                'current' => $this->display($field, $current),
                'default' => blank($current) ? 'lead' : 'keep',
            ];
        }

        return $rows;
    }

    protected function display(string $field, mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        return match ($field) {
            'owner_id' => (string) (User::query()->whereKey($value)->value('name') ?? $value),
            'preferred_locale' => Locale::tryFrom((string) $value)?->label() ?? (string) $value,
            default => (string) $value,
        };
    }
}
