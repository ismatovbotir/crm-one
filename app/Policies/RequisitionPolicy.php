<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Requisition;
use App\Models\User;

/**
 * Our side of customer requests (Spec 10 §6): only requests sent to us are visible; sales see
 * their customers' requests (owner scope), heads their department, managers all; admins read.
 * Portal users are handled by PortalScope.
 */
class RequisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal() && PermissionMap::allows($user, 'requisitions.view');
    }

    public function view(User $user, Requisition $requisition): bool
    {
        return $user->isInternal() && $requisition->status->isWithSupplier()
            && PermissionMap::allowsFor($user, 'requisitions.view', $requisition->owner_id ?? $requisition->organization?->owner_id);
    }

    public function manage(User $user, Requisition $requisition): bool
    {
        return $user->isInternal() && $requisition->status->isWithSupplier()
            && PermissionMap::allowsFor($user, 'requisitions.manage', $requisition->owner_id ?? $requisition->organization?->owner_id);
    }
}
