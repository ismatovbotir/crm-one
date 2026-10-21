<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use App\Services\OneC\OneCSettings;

/**
 * Spec 01 section 3 (customers & contacts), via the central permission map so department
 * memberships extend the global roles (Spec 03 section 4.9). Admin rights are explicit grants.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'customers.view');
    }

    public function view(User $user, Partner $customer): bool
    {
        return PermissionMap::allowsFor($user, 'customers.view', $customer->owner_id);
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'customers.create');
    }

    /**
     * Shared/general fields (name, address, contacts, ...). Archived and "deleted in 1C" records are read-only.
     */
    public function update(User $user, Partner $customer): bool
    {
        return $this->isEditable($customer) && PermissionMap::allowsFor($user, 'customers.update', $customer->owner_id);
    }

    public function changeOwner(User $user, Partner $customer): bool
    {
        return $this->isEditable($customer) && PermissionMap::allowsFor($user, 'customers.change_owner', $customer->owner_id);
    }

    /**
     * Payment terms (finance owns commercial terms).
     */
    public function updateCommercialTerms(User $user, Partner $customer): bool
    {
        return $this->isEditable($customer) && PermissionMap::allowsFor($user, 'customers.commercial_terms', $customer->owner_id);
    }

    /**
     * May open the edit form: any of the field groups is editable.
     */
    public function edit(User $user, Partner $customer): bool
    {
        return $this->update($user, $customer)
            || $this->changeOwner($user, $customer)
            || $this->updateCommercialTerms($user, $customer);
    }

    /**
     * Agreement details (Spec 06 4.2): bank accounts, VAT code, OKED, signatory. CRM-only fields.
     */
    /** Customer portal access (Spec 03 4.7): admin, manager, the customer owner, sales head. */
    public function managePortal(User $user, Partner $customer): bool
    {
        return ! $customer->trashed() && PermissionMap::allowsFor($user, 'customers.portal_access', $customer->owner_id);
    }

    public function manageFinanceDetails(User $user, Partner $customer): bool
    {
        return ! $customer->trashed() && PermissionMap::allowsFor($user, 'customers.finance_details', $customer->owner_id);
    }

    /**
     * Credit limit and manual hold (Spec 06 4.2: finance only).
     */
    public function manageCredit(User $user, Partner $customer): bool
    {
        return ! $customer->trashed() && PermissionMap::allows($user, 'accounting.credit');
    }

    public function manageContacts(User $user, Partner $customer): bool
    {
        return $this->update($user, $customer);
    }

    public function archive(User $user, Partner $customer): bool
    {
        return ! $customer->trashed() && PermissionMap::allowsFor($user, 'customers.archive', $customer->owner_id);
    }

    public function restore(User $user, Partner $customer): bool
    {
        return $customer->trashed() && PermissionMap::allows($user, 'customers.restore');
    }

    /**
     * Re-queue the customer in the 1C outbox (Spec 02 rev. 3). Only while the exchange is on.
     */
    public function resync(User $user, Partner $customer): bool
    {
        return app(OneCSettings::class)->customersEnabled()
            && $customer->syncsWithOneC()
            && $this->isEditable($customer)
            && PermissionMap::allowsFor($user, 'customers.resync', $customer->owner_id);
    }

    public function viewActivity(User $user, Partner $customer): bool
    {
        return $this->view($user, $customer) && PermissionMap::allows($user, 'activity.view');
    }

    public function delete(User $user, Partner $customer): bool
    {
        return false;
    }

    public function forceDelete(User $user, Partner $customer): bool
    {
        return false;
    }

    private function isEditable(Partner $customer): bool
    {
        return ! $customer->trashed() && ! $customer->isDeletedInOneC();
    }
}
