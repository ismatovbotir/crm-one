<?php

namespace App\Services\Organizations;

use App\Enums\CounterpartyStatus;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Roles of an organization (Spec 03 section 22): one organization per tax ID; adding the customer
 * role to a supplier/carrier, or the supplier/carrier role (a `counterparties` profile) to a
 * customer, instead of creating a duplicate. Nothing on the organization is overwritten; every
 * role change is audited on the organization.
 */
class OrganizationRoles
{
    /** The organization holding this tax ID (any role, archived included), or null. */
    public function findByTaxId(?string $taxId, ?int $ignoreOrganizationId = null): ?Partner
    {
        $taxId = Customer::normalizeTaxId($taxId);

        if ($taxId === null) {
            return null;
        }

        return Partner::withTrashed()->where('tax_id', $taxId)
            ->when($ignoreOrganizationId !== null, fn ($query) => $query->whereKeyNot($ignoreOrganizationId))
            ->orderByRaw("case origin when 'crm' then 0 else 1 end")->orderBy('id')->first();
    }

    /**
     * Adds the customer role; the organization is then offered to 1C as a new customer (CRM origin).
     */
    public function addCustomerRole(Partner $organization, User $by): Customer
    {
        if (! $organization->is_customer) {
            DB::transaction(function () use ($organization, $by): void {
                $organization->forceFill(['is_customer' => true, 'owner_id' => $organization->owner_id ?? $by->id])->save();
                $organization->logActivity('role_added', ['role' => 'customer']);
            });
        }

        return Customer::query()->findOrFail($organization->id);
    }

    /**
     * Adds the supplier and/or carrier role: creates the organization's supplier profile (status
     * prospect) or switches the roles on an existing one.
     *
     * @param  array{is_supplier: bool, is_carrier: bool}  $roles
     */
    public function addSupplierRole(Partner $organization, array $roles, User $by): Counterparty
    {
        return DB::transaction(function () use ($organization, $roles, $by): Counterparty {
            $profile = Counterparty::withTrashed()->where('partner_id', $organization->id)->first();

            if ($profile === null) {
                $profile = new Counterparty([
                    ...$organization->only(Customer::IDENTITY_FIELDS),
                    'is_supplier' => $roles['is_supplier'],
                    'is_carrier' => $roles['is_carrier'],
                    'status' => CounterpartyStatus::Prospect,
                    'owner_id' => $by->id,
                    'department_id' => $by->activeDepartments()->first()?->id,
                ]);
                $profile->partner_id = $organization->id;
                $profile->save();
            } else {
                if ($profile->trashed()) {
                    $profile->restore();
                }

                $profile->forceFill(['is_supplier' => $profile->is_supplier || $roles['is_supplier'], 'is_carrier' => $profile->is_carrier || $roles['is_carrier']])->save();
            }

            $organization->logActivity('role_added', ['role' => array_keys(array_filter(['supplier' => $roles['is_supplier'], 'carrier' => $roles['is_carrier']]))]);

            return $profile;
        });
    }
}
