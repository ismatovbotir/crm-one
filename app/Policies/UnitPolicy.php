<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Unit;
use App\Models\User;

/**
 * Units come from 1C; nothing is editable in the CRM (the units page was removed; precision is seed/1C-managed).
 */
class UnitPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'catalog.view');
    }

    public function view(User $user, Unit $unit): bool
    {
        return PermissionMap::allows($user, 'catalog.view');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Unit $unit): bool
    {
        return false;
    }

    public function delete(User $user, Unit $unit): bool
    {
        return false;
    }
}
