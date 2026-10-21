<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Models\ItemSerial;
use App\Models\User;

/**
 * Serial search and history (Spec 06 21.3): stock, logistics, managers, accountants and finance see
 * all serials; sales only serials sold on their orders. The buyer of a sold serial is shown only
 * with `serials.view_customer` on that order.
 */
class ItemSerialPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'serials.view');
    }

    public function view(User $user, ItemSerial $serial): bool
    {
        if (PermissionMap::scopeFor($user, 'serials.view') === DataScope::All) {
            return true;
        }

        return PermissionMap::allowsFor($user, 'serials.view', $serial->soldOrderLine?->order?->owner_id);
    }

    public function viewCustomer(User $user, ItemSerial $serial): bool
    {
        return PermissionMap::allowsFor($user, 'serials.view_customer', $serial->soldOrderLine?->order?->owner_id)
            || PermissionMap::scopeFor($user, 'serials.view_customer') === DataScope::All;
    }

    public function inspect(User $user, ItemSerial $serial): bool
    {
        return PermissionMap::allows($user, 'stock.receive');
    }

    public function viewReconciliation(User $user): bool
    {
        return PermissionMap::allows($user, 'stock.reconciliation');
    }

    /** Add / import serials from the catalogue (manual registration). */
    public function register(User $user): bool
    {
        return PermissionMap::allows($user, 'serials.register');
    }
}
