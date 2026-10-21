<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\User;

/**
 * Accounting screens (Spec 06 4.2): periods and FX rates (finance), Settings > Accounting (finance
 * edits; manager and accountant view), receivables (scoped like orders).
 */
class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'accounting.view_settings');
    }

    public function manage(User $user): bool
    {
        return PermissionMap::allows($user, 'accounting.periods');
    }

    public function viewSettings(User $user): bool
    {
        return PermissionMap::allows($user, 'accounting.view_settings');
    }

    public function editSettings(User $user): bool
    {
        return PermissionMap::allows($user, 'accounting.settings');
    }

    public function viewReceivables(User $user): bool
    {
        return PermissionMap::allows($user, 'receivables.view');
    }
}
