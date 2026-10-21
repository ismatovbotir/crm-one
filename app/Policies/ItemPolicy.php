<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\Item;
use App\Models\User;

/**
 * Items are a read-only mirror of 1C (Spec 02 BR-I.2): nobody creates, edits or deletes them in the
 * CRM, not even admin. Only the CRM-local min order quantity / order step are editable.
 */
class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'catalog.view');
    }

    public function view(User $user, Item $item): bool
    {
        return PermissionMap::allows($user, 'catalog.view');
    }

    public function updateLocalFields(User $user, Item $item): bool
    {
        return ! $item->trashed() && PermissionMap::allows($user, 'catalog.edit_local');
    }

    /**
     * Accountant code, IKPU class code and package code (Spec 02 BR-I.13a).
     */
    public function updateAccountingCodes(User $user, Item $item): bool
    {
        return ! $item->trashed() && PermissionMap::allows($user, 'catalog.edit_accounting_codes');
    }

    /**
     * Serial tracking flag (Spec 02 BR-I.13b). TODO Spec 06: block changes while serials exist.
     */
    /** CRM-local warranty months used for `warranty_until` of sold serials (Spec 06 21.5). */
    public function updateWarranty(User $user, Item $item): bool
    {
        return ! $item->trashed() && PermissionMap::allows($user, 'catalog.edit_warranty');
    }

    public function updateSerialTracking(User $user, Item $item): bool
    {
        return ! $item->trashed() && PermissionMap::allows($user, 'catalog.edit_serial_tracking');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Item $item): bool
    {
        return false;
    }

    public function delete(User $user, Item $item): bool
    {
        return false;
    }

    public function restore(User $user, Item $item): bool
    {
        return false;
    }

    public function forceDelete(User $user, Item $item): bool
    {
        return false;
    }

    /** CRM-owned pictures (Spec 01 section 15): `catalog.edit_local` (manager, admin). */
    public function manageImages(User $user, Item $item): bool
    {
        return ! $item->trashed() && PermissionMap::allows($user, 'catalog.edit_local');
    }

    /** Spec 08 10.1: CRM-local cargo data used to prefill transport tenders. */
    public function updateCargo(User $user, Item $item): bool
    {
        return ! $item->trashed() && PermissionMap::allows($user, 'catalog.edit_cargo');
    }
}
