<?php

namespace App\Services\Leads;

use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Services\Customers\DuplicateCustomerFinder;
use App\Support\UzbekIdentifiers;
use Illuminate\Support\Collection;

/**
 * Duplicate warnings at lead capture (Spec 04 BR-3): customers by exact tax ID, normalised name,
 * e-mail or phone (Spec 01 finder) and open leads with the same tax ID, e-mail, phone or company
 * name. Warnings only; nothing is merged or blocked. Results show name, number and owner only, so
 * a rep learns that a colleague already works the company without opening the record.
 */
class LeadDuplicateFinder
{
    public function __construct(protected DuplicateCustomerFinder $customers) {}

    /**
     * @return Collection<int, array{customer: Customer, reasons: list<string>}>
     */
    public function customers(?string $taxId, ?string $name, ?string $email, ?string $phone): Collection
    {
        $warnings = $this->customers->warnings($name, $email, UzbekIdentifiers::normalizePhone($phone));
        $byTaxId = $this->customers->findByTaxId($taxId);

        if ($byTaxId !== null) {
            $existing = $warnings->search(fn (array $row): bool => $row['customer']->id === $byTaxId->id);

            if ($existing === false) {
                $warnings->prepend(['customer' => $byTaxId->loadMissing('owner'), 'reasons' => ['tax_id']]);
            } else {
                $row = $warnings->pull($existing);
                $warnings->prepend(['customer' => $row['customer'], 'reasons' => ['tax_id', ...$row['reasons']]]);
            }
        }

        return $warnings->filter(fn (array $row): bool => ! $row['customer']->trashed())->values()->take(5);
    }

    /**
     * @return Collection<int, Lead>
     */
    public function openLeads(?string $taxId, ?string $name, ?string $email, ?string $phone, ?int $customerId, ?int $ignoreId = null): Collection
    {
        $taxId = Customer::normalizeTaxId($taxId);
        $email = filled($email) ? mb_strtolower(trim((string) $email)) : null;
        $phone = UzbekIdentifiers::normalizePhone($phone);
        $name = filled($name) ? trim((string) $name) : null;

        if ($taxId === null && $email === null && $phone === null && ($name === null || mb_strlen($name) < 3) && $customerId === null) {
            return collect();
        }

        return Lead::query()
            ->with('owner:id,name')
            ->whereIn('status', LeadStatus::openValues())
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where(function ($query) use ($taxId, $email, $phone, $name, $customerId): void {
                $query->whereRaw('1 = 0')
                    ->when($taxId !== null, fn ($q) => $q->orWhere('tax_id', $taxId))
                    ->when($email !== null, fn ($q) => $q->orWhere('contact_email', $email))
                    ->when($phone !== null, fn ($q) => $q->orWhere('contact_phone', $phone))
                    ->when($name !== null && mb_strlen($name) >= 3, fn ($q) => $q->orWhereRaw('lower(company_name) = ?', [mb_strtolower($name)]))
                    ->when($customerId !== null, fn ($q) => $q->orWhere('customer_id', $customerId));
            })
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'number', 'status', 'owner_id', 'company_name', 'customer_id']);
    }

    /**
     * Exact duplicates that need an override reason (section 16): a customer with the same tax ID,
     * or an open lead with the same tax ID, e-mail or phone. Name similarity is a warning only.
     */
    public function needsOverride(?string $taxId, ?string $email, ?string $phone, ?int $customerId, ?int $ignoreLeadId = null): bool
    {
        if ($customerId !== null) {
            return false;
        }

        $customer = $this->customers->findByTaxId($taxId);

        if ($customer !== null && ! $customer->trashed()) {
            return true;
        }

        return $this->openLeads($taxId, null, $email, $phone, null, $ignoreLeadId)->isNotEmpty();
    }
}
