<?php

namespace App\Policies;

use App\Actions\StockAdjustment\TransitionStockAdjustment;
use App\Authorization\PermissionMap;
use App\Enums\StockAdjustmentStatus;
use App\Models\StockAdjustment;
use App\Models\User;

/**
 * Spec 06 21.4: stock staff and the warehouse head request adjustments; the warehouse head or a
 * manager approves (which posts it), never their own request; managers, management, purchasing,
 * accountants and finance read them.
 */
class StockAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'stock_adjust.view');
    }

    public function view(User $user, StockAdjustment $adjustment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'stock_adjust.create');
    }

    public function update(User $user, StockAdjustment $adjustment): bool
    {
        return $adjustment->status->isEditable() && $this->create($user);
    }

    public function transition(User $user, StockAdjustment $adjustment, StockAdjustmentStatus $to): bool
    {
        return TransitionStockAdjustment::allows($user, $adjustment, $to);
    }

    public function delete(User $user, StockAdjustment $adjustment): bool
    {
        return false;
    }
}
