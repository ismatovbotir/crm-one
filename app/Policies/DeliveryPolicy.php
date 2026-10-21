<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\OrderStatus;
use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;

/**
 * Spec 06 21.3 / section 25 (SoD-S3): the packing list (draft delivery note) is warehouse work:
 * stock managers, warehouse staff and the warehouse head draft it, register serials, complete or
 * reopen packaging and post the delivery. Sales and logistics follow it read-only.
 */
class DeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'stock.view') || PermissionMap::allows($user, 'stock.delivery_draft');
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return PermissionMap::allows($user, 'stock.view') || ($delivery->order !== null && $user->can('view', $delivery->order));
    }

    /** Packing list (draft delivery note) of an order in packaging (section 25): warehouse only. */
    public function create(User $user, ?Order $order = null): bool
    {
        return $order === null
            ? PermissionMap::allows($user, 'orders.pack')
            : $order->status === OrderStatus::Packing && PermissionMap::allowsFor($user, 'orders.pack', $order->owner_id);
    }

    /** Edit plain quantities of an unpacked packing list or cancel it. */
    public function update(User $user, Delivery $delivery): bool
    {
        return $delivery->status === StockDocumentStatus::Draft && ! $delivery->isPacked() && PermissionMap::allows($user, 'stock.deliver');
    }

    public function registerSerials(User $user, Delivery $delivery): bool
    {
        return $delivery->status === StockDocumentStatus::Draft && ! $delivery->isPacked() && PermissionMap::allows($user, 'stock.deliver');
    }

    public function completePackaging(User $user, Delivery $delivery): bool
    {
        return $this->registerSerials($user, $delivery) && $delivery->order?->status === OrderStatus::Packing;
    }

    public function reopenPackaging(User $user, Delivery $delivery): bool
    {
        return $delivery->status === StockDocumentStatus::Draft && $delivery->isPacked() && $delivery->order?->status === OrderStatus::Packed && PermissionMap::allows($user, 'stock.deliver');
    }

    /** Delivery is posted only from a complete packing list (gate G4). */
    public function post(User $user, Delivery $delivery): bool
    {
        return $delivery->status === StockDocumentStatus::Draft && $delivery->isPacked() && $delivery->order?->status === OrderStatus::Packed && PermissionMap::allows($user, 'stock.deliver');
    }

    public function print(User $user, Delivery $delivery): bool
    {
        return $this->view($user, $delivery);
    }

    public function delete(User $user, Delivery $delivery): bool
    {
        return false;
    }
}
