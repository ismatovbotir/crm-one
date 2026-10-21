<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;

/**
 * Partners (Spec 03 section 24). `partners.view` sees every organization; otherwise a user sees the
 * partners of the roles they may see (customers.view → customer role, counterparties.view →
 * supplier/carrier role). Creating needs the right of at least one role; each role change needs the
 * right of that role. Customer-side rules (identity, finance, portal) stay in CustomerPolicy, which
 * this policy inherits for partners in the customer role.
 */
class PartnerPolicy extends CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'partners.view') || PermissionMap::allows($user, 'customers.view') || PermissionMap::allows($user, 'counterparties.view');
    }

    /** Sees every partner (no role filter on the Partners list). */
    public function viewAll(User $user): bool
    {
        return PermissionMap::allows($user, 'partners.view');
    }

    public function view(User $user, Partner $customer): bool
    {
        if ($this->viewAll($user)) {
            return true;
        }

        return ($customer->is_customer && parent::view($user, $customer))
            || ($customer->isSupplierOrCarrier() && $user->can('viewAny', Counterparty::class));
    }

    public function create(User $user): bool
    {
        return $user->can('create', Customer::class) || $user->can('create', Counterparty::class);
    }

    /** Role values the user may give (or take away). */
    public function editRoles(User $user, Partner $partner): bool
    {
        return ! $partner->trashed() && $this->allowedRoles($user) !== [];
    }

    /**
     * @return list<string> customer|supplier|carrier
     */
    public function allowedRoles(User $user): array
    {
        return array_values(array_filter([
            $user->can('create', Customer::class) ? 'customer' : null,
            $user->can('create', Counterparty::class) ? 'supplier' : null,
            $user->can('create', Counterparty::class) ? 'carrier' : null,
        ]));
    }
}
