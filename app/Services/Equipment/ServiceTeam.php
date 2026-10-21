<?php

namespace App\Services\Equipment;

use App\Authorization\PermissionMap;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who handles service work (Spec 09 §7): active internal users with `service.manage`.
 */
class ServiceTeam
{
    /**
     * @return Collection<int, User>
     */
    public static function members(): Collection
    {
        return User::query()->internal()->active()->get()->filter(fn (User $user): bool => PermissionMap::allows($user, 'service.manage'))->values();
    }
}
