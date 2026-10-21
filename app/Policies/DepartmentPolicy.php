<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Models\Department;
use App\Models\User;

/**
 * Spec 03 section 4.10: admin manages departments and memberships; managers view all;
 * department heads and specialists view their own departments.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'departments.view');
    }

    public function view(User $user, Department $department): bool
    {
        return match (PermissionMap::scopeFor($user, 'departments.view')) {
            DataScope::All => true,
            DataScope::Department => $user->activeDepartments()->contains('id', $department->id),
            default => false,
        };
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'departments.manage');
    }

    public function update(User $user, Department $department): bool
    {
        return PermissionMap::allows($user, 'departments.manage');
    }

    public function manageMembers(User $user, Department $department): bool
    {
        return PermissionMap::allows($user, 'departments.manage') && $department->is_active;
    }

    public function delete(User $user, Department $department): bool
    {
        return false;
    }

    public function restore(User $user, Department $department): bool
    {
        return false;
    }

    public function forceDelete(User $user, Department $department): bool
    {
        return false;
    }
}
