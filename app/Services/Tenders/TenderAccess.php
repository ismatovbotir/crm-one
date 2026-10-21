<?php

namespace App\Services\Tenders;

use App\Authorization\PermissionMap;
use App\Enums\DepartmentRole;
use App\Enums\TenderType;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\User;

/**
 * Record-level tender rights shared by policies and actions (Spec 03 4.10, BR-T.5, DEC-10;
 * Spec 08 BR-B.4): who manages a tender, who belongs to its department, who may read sealed bid
 * content. Admins never read bid content and never approve.
 */
class TenderAccess
{
    /** Department code(s) whose members work on tenders of the type. */
    public static function departmentCodes(TenderType $type): array
    {
        return $type === TenderType::Transport ? ['logistics'] : ['purchase'];
    }

    public static function isDepartmentMember(User $user, Tender $tender, ?DepartmentRole $role = null): bool
    {
        return $user->activeDepartments()->contains(
            fn (Department $department): bool => (in_array($department->code, self::departmentCodes($tender->type), true) || $department->id === $tender->department_id)
                && ($role === null || $department->membership->role === $role),
        );
    }

    /**
     * Edit, submit, publish, invite, answer questions, enter bids, evaluate and propose awards:
     * the owner and whoever the create ability covers for that owner; unowned requests can be taken
     * by any department member allowed to create tenders of the type.
     */
    public static function canManage(User $user, Tender $tender): bool
    {
        if ($user->hasRole(UserRole::Admin)) {
            return false;
        }

        $ability = 'tenders.'.$tender->type->value.'.create';

        if ($tender->owner_id === null) {
            return PermissionMap::allows($user, $ability);
        }

        return PermissionMap::allowsFor($user, $ability, $tender->owner_id);
    }

    /**
     * Bid amounts, lines and attachments (BR-T.5, AC-21): before opening only the employee who
     * entered that bid version; after opening users with `tenders.view_bids` in the tender's
     * department (or management/finance/manager), and committee members who declared no conflict
     * of interest. Never an admin.
     */
    public static function canViewBidContent(User $user, TenderBid $bid): bool
    {
        if ($user->hasRole(UserRole::Admin) || ! $user->isInternal() || ! $user->is_active) {
            return false;
        }

        $tender = $bid->tender;

        if (! $tender->isOpened()) {
            return $bid->entered_by !== null && $bid->entered_by === $user->id;
        }

        return self::canViewOpenedBids($user, $tender);
    }

    public static function canViewOpenedBids(User $user, Tender $tender): bool
    {
        if ($user->hasRole(UserRole::Admin) || ! $tender->isOpened()) {
            return false;
        }

        $member = $tender->committee()->where('user_id', $user->id)->first();

        if ($member !== null) {
            return $member->coi_declared_at !== null;
        }

        if (! PermissionMap::allows($user, 'tenders.view_bids') || ! Tender::query()->whereKey($tender->id)->visibleTo($user)->exists()) {
            return false;
        }

        return $user->hasRole(UserRole::Manager)
            || $user->hasRole(UserRole::Finance)
            || PermissionMap::matchesGrant($user, 'dept:management:*')
            || PermissionMap::matchesGrant($user, 'dept:finance:head')
            || self::isDepartmentMember($user, $tender)
            || ($tender->type === TenderType::Transport && PermissionMap::matchesGrant($user, 'dept:purchase:*'));
    }
}
