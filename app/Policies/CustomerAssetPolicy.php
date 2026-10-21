<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\Partner;
use App\Models\User;

/**
 * Staff access to customer equipment (Spec 09 §7). The service team sees all; the customer's sales
 * owner sees their customers' equipment (read-only) and may register units; admins read. Portal
 * users are handled by PortalScope, never by this policy.
 */
class CustomerAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal() && PermissionMap::allows($user, 'service.view');
    }

    public function view(User $user, CustomerAsset $asset): bool
    {
        return $user->isInternal() && (PermissionMap::allowsFor($user, 'service.view', $asset->organization?->owner_id) || PermissionMap::allowsFor($user, 'service.register', $asset->organization?->owner_id));
    }

    /** Register a unit for a customer (staff_registered, verified). */
    public function register(User $user, Partner $customer): bool
    {
        return $user->isInternal() && PermissionMap::allowsFor($user, 'service.register', $customer->owner_id);
    }

    /** Verify a customer-registered unit, write off / reactivate, open a ticket for it. */
    public function manage(User $user, CustomerAsset $asset): bool
    {
        return $user->isInternal() && PermissionMap::allows($user, 'service.manage');
    }
}
