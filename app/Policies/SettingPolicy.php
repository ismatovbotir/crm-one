<?php

namespace App\Policies;

use App\Models\User;

/**
 * Application settings (appearance, etc.) are admin-only.
 */
class SettingPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
