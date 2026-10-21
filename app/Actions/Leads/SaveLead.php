<?php

namespace App\Actions\Leads;

use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Locale;
use App\Enums\SaleType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Lead;
use App\Models\NumberSequence;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Leads\LeadScore;
use App\Services\Notifications\Notifier;
use App\Support\UzbekIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a lead with its interested items (Spec 04 BR-1, BR-2): number LD-YYYY-NNNNN
 * on creation, owner = creator unless an assignee is given, a company (customer or free text) and
 * a way to reach the contact are required; the contact must belong to the linked customer.
 * Free-text company/contact fields are kept as captured (no silent normalisation).
 */
class SaveLead
{
    /**
     * @param  array{priority?: string|null, sale_type?: string|null, locale?: string|null, duplicate_override_reason?: string|null, source: string, campaign?: string|null, customer_id?: int|null, contact_id?: int|null, company_name?: string|null, tax_id?: string|null, industry?: string|null, contact_name?: string|null, contact_position?: string|null, contact_email?: string|null, contact_phone?: string|null, need?: string|null, est_value_minor?: int|null, currency: string, expected_close_date?: string|null, budget_confirmed?: bool, authority_confirmed?: bool, need_confirmed?: bool, timeline_confirmed?: bool, owner_id?: int|null}  $data
     * @param  array<int, string>  $items  item id => quantity (decimal string)
     * @param  array{due_at: string, note?: string|null, remind?: bool}|null  $followUp  section 16: follow-up task for the owner
     */
    public function handle(User $user, array $data, array $items, ?Lead $lead = null, ?array $followUp = null): Lead
    {
        $customer = filled($data['customer_id'] ?? null) ? Customer::query()->find((int) $data['customer_id']) : null;
        $contact = filled($data['contact_id'] ?? null) ? Contact::query()->find((int) $data['contact_id']) : null;

        if ($customer !== null && ($customer->trashed() || $customer->isDeletedInOneC())) {
            throw new LeadActionException('customer_unavailable');
        }

        if ($contact !== null && ($customer === null || $contact->partner_id !== $customer->id)) {
            throw new LeadActionException('contact_mismatch');
        }

        if ($customer === null && blank($data['company_name'] ?? null)) {
            throw new LeadActionException('company_required');
        }

        if ($contact === null && blank($data['contact_email'] ?? null) && blank($data['contact_phone'] ?? null)) {
            throw new LeadActionException('reach_required');
        }

        if (count($items) > (int) config('leads.max_items', 50)) {
            throw new LeadActionException('too_many_items', ['max' => (int) config('leads.max_items', 50)]);
        }

        $sellable = Item::query()->whereKey(array_keys($items))->pluck('id')->all();

        if (count($sellable) !== count($items)) {
            throw new LeadActionException('item_unavailable');
        }

        $attributes = [
            'source' => LeadSource::from($data['source']),
            'campaign' => $this->clean($data['campaign'] ?? null),
            'customer_id' => $customer?->id,
            'contact_id' => $contact?->id,
            'company_name' => $this->clean($data['company_name'] ?? null),
            'tax_id' => $this->clean($data['tax_id'] ?? null),
            'industry' => $this->clean($data['industry'] ?? null),
            'contact_name' => $this->clean($data['contact_name'] ?? null),
            'contact_position' => $this->clean($data['contact_position'] ?? null),
            'contact_email' => $this->clean(isset($data['contact_email']) ? mb_strtolower((string) $data['contact_email']) : null),
            'contact_phone' => UzbekIdentifiers::normalizePhone($this->clean($data['contact_phone'] ?? null)),
            'need' => $this->clean($data['need'] ?? null),
            'est_value_minor' => $data['est_value_minor'] ?? null,
            'currency' => strtoupper($data['currency']),
            'expected_close_date' => $this->clean($data['expected_close_date'] ?? null),
            'budget_confirmed' => (bool) ($data['budget_confirmed'] ?? false),
            'authority_confirmed' => (bool) ($data['authority_confirmed'] ?? false),
            'need_confirmed' => (bool) ($data['need_confirmed'] ?? false),
            'timeline_confirmed' => (bool) ($data['timeline_confirmed'] ?? false),
            'priority' => LeadPriority::tryFrom((string) ($data['priority'] ?? '')) ?? LeadPriority::Normal,
            'sale_type' => SaleType::tryFrom((string) ($data['sale_type'] ?? '')),
            'locale' => Locale::fromAvailable($data['locale'] ?? null)?->value,
        ];

        return DB::transaction(function () use ($user, $data, $items, $lead, $attributes, $followUp): Lead {
            if ($lead === null) {
                $year = (int) CarbonImmutable::now((string) config('offers.timezone', 'Asia/Tashkent'))->format('Y');
                $prefix = (string) config('leads.number_prefix', 'LD');

                $lead = Lead::query()->create([
                    ...$attributes,
                    'number' => NumberSequence::format($prefix, $year, NumberSequence::next($prefix, $year)),
                    'status' => LeadStatus::New,
                    'owner_id' => (int) ($data['owner_id'] ?? $user->id),
                    'created_by' => $user->id,
                    'last_activity_at' => now(),
                ]);

                TransitionLead::recordCreation($lead, $user);
                $created = $lead;
                DB::afterCommit(fn () => app(Notifier::class)->leadAssigned($created, $user));
            } else {
                $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

                if (! $lead->status->isOpen()) {
                    throw new LeadActionException('read_only');
                }

                $lead->update([...$attributes, ...(isset($data['owner_id']) ? ['owner_id' => (int) $data['owner_id']] : [])]);
            }

            $this->syncItems($lead, $items);
            $lead->forceFill(['score' => $this->score($lead, count($items))])->saveQuietly();

            if (filled($data['duplicate_override_reason'] ?? null)) {
                $reason = trim((string) $data['duplicate_override_reason']);
                $lead->forceFill(['duplicate_override_reason' => $reason])->saveQuietly();
                $lead->logActivity('duplicate_override', ['reason' => $reason]);
            }

            if ($followUp !== null && filled($followUp['due_at'] ?? null)) {
                $due = CarbonImmutable::parse($followUp['due_at']);
                $lead->followUps()->create([
                    'assignee_id' => $lead->owner_id,
                    'created_by' => $user->id,
                    'due_at' => $due,
                    'remind_at' => ($followUp['remind'] ?? true) ? $due : null,
                    'note' => filled($followUp['note'] ?? null) ? mb_substr(trim((string) $followUp['note']), 0, 500) : null,
                ]);
            }

            return $lead;
        });
    }

    /**
     * @param  array<int, string>  $items
     */
    protected function syncItems(Lead $lead, array $items): void
    {
        $lead->items()->whereNotIn('item_id', array_keys($items))->delete();

        foreach ($items as $itemId => $quantity) {
            $lead->items()->updateOrCreate(['item_id' => (int) $itemId], ['quantity' => $quantity]);
        }
    }

    public function score(Lead $lead, int $itemCount): int
    {
        return app(LeadScore::class)->calculate([
            'budget_confirmed' => $lead->budget_confirmed, 'authority_confirmed' => $lead->authority_confirmed,
            'need_confirmed' => $lead->need_confirmed, 'timeline_confirmed' => $lead->timeline_confirmed,
            'customer' => $lead->customer_id !== null, 'reachable' => $lead->contact_id !== null || filled($lead->contact_email) || filled($lead->contact_phone),
            'need' => $lead->need, 'est_value_minor' => $lead->est_value_minor, 'expected_close_date' => $lead->expected_close_date?->toDateString(),
            'items' => $itemCount, 'priority' => $lead->priority?->value,
        ]);
    }

    protected function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return blank($value) ? null : (string) $value;
    }
}
