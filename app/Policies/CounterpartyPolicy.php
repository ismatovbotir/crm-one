<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Counterparty;
use App\Models\User;

/**
 * Spec 03 4.10: purchase, logistics, management, finance and admins see counterparties; purchase
 * and logistics (and managers/admins) maintain them; department heads and managers qualify,
 * suspend or blacklist. Archived, never hard-deleted.
 */
class CounterpartyPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'counterparties.view');
    }

    public function view(User $user, Counterparty $counterparty): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'counterparties.manage');
    }

    public function update(User $user, Counterparty $counterparty): bool
    {
        return ! $counterparty->trashed() && $this->create($user);
    }

    public function qualify(User $user, Counterparty $counterparty): bool
    {
        return ! $counterparty->trashed() && PermissionMap::allows($user, 'counterparties.qualify');
    }

    public function rate(User $user, Counterparty $counterparty): bool
    {
        return $this->update($user, $counterparty);
    }

    public function downloadDocument(User $user, Counterparty $counterparty): bool
    {
        return $this->view($user, $counterparty);
    }

    public function delete(User $user, Counterparty $counterparty): bool
    {
        return false;
    }
}
