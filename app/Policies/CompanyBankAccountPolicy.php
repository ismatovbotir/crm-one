<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\User;

/**
 * Settings > Company (Spec 06 7.9): legal profile, our bank accounts and signatories
 * (`company.manage`: admin, manager, finance).
 */
class CompanyBankAccountPolicy
{
    public function manage(User $user): bool
    {
        return PermissionMap::allows($user, 'company.manage');
    }
}
