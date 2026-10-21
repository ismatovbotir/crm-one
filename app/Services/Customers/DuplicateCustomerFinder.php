<?php

namespace App\Services\Customers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Partner;
use App\Support\CustomerNameNormalizer;
use Illuminate\Support\Collection;

/**
 * Duplicate detection for customers (Spec 01 BR-5/BR-6, Spec 02 BR-I.17):
 * an existing tax ID (any origin, including archived rows) blocks creation in the CRM;
 * same normalised name, email or phone only warns.
 */
class DuplicateCustomerFinder
{
    public function findByTaxId(?string $taxId, ?int $ignoreId = null): ?Partner
    {
        $taxId = Customer::normalizeTaxId($taxId);

        if ($taxId === null) {
            return null;
        }

        // Spec 03 section 22: a tax ID is unique per organization, whatever its roles.
        return Partner::withTrashed()
            ->where('tax_id', $taxId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('id')
            ->first();
    }

    /**
     * Non-blocking warnings: [customer, reason] with reason name|email|phone. Max 5 customers.
     *
     * @return Collection<int, array{customer: Customer, reasons: list<string>}>
     */
    public function warnings(?string $name, ?string $email, ?string $phone, ?int $ignoreId = null): Collection
    {
        $normalizedName = CustomerNameNormalizer::normalize($name);
        $email = filled($email) ? mb_strtolower(trim($email)) : null;
        $phone = Customer::normalizePhone($phone);

        /** @var array<int, list<string>> $reasons */
        $reasons = [];

        $add = function (iterable $ids, string $reason) use (&$reasons, $ignoreId): void {
            foreach ($ids as $id) {
                if ($id !== $ignoreId && ! in_array($reason, $reasons[$id] ?? [], true)) {
                    $reasons[$id][] = $reason;
                }
            }
        };

        if (mb_strlen($normalizedName) >= 3) {
            $add(Customer::withTrashed()->where('name_normalized', $normalizedName)->limit(5)->pluck('id'), 'name');
        }

        if ($email !== null) {
            $add(Customer::withTrashed()->where('email', $email)->limit(5)->pluck('id'), 'email');
            $add(Contact::query()->where('email', $email)->limit(5)->pluck('partner_id'), 'email');
        }

        if ($phone !== null && strlen($phone) >= 7) {
            $add(Customer::withTrashed()->where('phone', $phone)->limit(5)->pluck('id'), 'phone');
            $add(Contact::query()->where('phone', $phone)->limit(5)->pluck('partner_id'), 'phone');
        }

        if ($reasons === []) {
            return collect();
        }

        $customers = Customer::withTrashed()->with('owner')->whereKey(array_keys($reasons))->limit(5)->get();

        return $customers->map(fn (Partner $customer): array => [
            'customer' => $customer,
            'reasons' => $reasons[$customer->id],
        ])->values();
    }
}
