<?php

namespace App\Policies;

use App\Models\User;

/**
 * User management is admin-only (spec 01, section 3). Admin rights are explicit
 * here; there is no Gate::before bypass.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Admins cannot change their own role, so they can never lock themselves out.
     */
    public function changeRole(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model);
    }

    /**
     * Activate or deactivate an account. Never one's own.
     */
    public function toggleActive(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model);
    }

    /**
     * Reset another user's two-step verification (lost phone; roadmap step 10). Admin only, never
     * one's own (use the cabinet), so one admin cannot silently weaken their own login.
     */
    public function resetTwoFactor(User $user, User $model): bool
    {
        return $user->isAdmin() && ! $user->is($model) && $model->isInternal();
    }

    /**
     * Users are never hard-deleted: their records must stay attributable.
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
