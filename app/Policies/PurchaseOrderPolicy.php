<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Spec 08 12.4 (`purchase_orders.*`): purchase specialists their own POs, heads their department,
 * managers all; logistics, stock managers, accountants and finance read. Never deleted.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'purchase_orders.view');
    }

    public function view(User $user, PurchaseOrder $po): bool
    {
        return PermissionMap::allowsFor($user, 'purchase_orders.view', $po->owner_id);
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'purchase_orders.manage');
    }

    /** Header and lines while the PO is a draft. */
    public function edit(User $user, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Draft && PermissionMap::allowsFor($user, 'purchase_orders.manage', $po->owner_id);
    }

    /**
     * Spec 08 BR-PO.4: confirmed PO whose Incoterm leaves a leg to us; purchase raises a request,
     * logistics creates its own tender.
     */
    public function createTransportTender(User $user, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Confirmed
            && $po->buyerHasTransportLeg()
            && $this->view($user, $po)
            && (PermissionMap::allows($user, 'tenders.transport.request') || PermissionMap::allows($user, 'tenders.transport.create'));
    }

    public function confirm(User $user, PurchaseOrder $po): bool
    {
        return $po->status === PurchaseOrderStatus::Draft && PermissionMap::allowsFor($user, 'purchase_orders.manage', $po->owner_id);
    }

    public function cancel(User $user, PurchaseOrder $po): bool
    {
        return $po->status->canTransitionTo(PurchaseOrderStatus::Cancelled) && PermissionMap::allowsFor($user, 'purchase_orders.manage', $po->owner_id);
    }

    public function update(User $user, PurchaseOrder $po): bool
    {
        return ! in_array($po->status, [PurchaseOrderStatus::Cancelled, PurchaseOrderStatus::Closed], true) && PermissionMap::allowsFor($user, 'purchase_orders.manage', $po->owner_id);
    }

    public function print(User $user, PurchaseOrder $po): bool
    {
        return $this->view($user, $po);
    }

    public function delete(User $user, PurchaseOrder $po): bool
    {
        return false;
    }
}
