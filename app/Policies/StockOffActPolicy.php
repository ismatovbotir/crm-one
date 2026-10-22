<?php

namespace App\Policies;

use App\Actions\StockOff\TransitionStockOffAct;
use App\Authorization\PermissionMap;
use App\Enums\StockOffStatus;
use App\Models\StockOffAct;
use App\Models\User;

/**
 * Spec 06 27.5: stock staff and the warehouse head create and post acts; the warehouse head or a
 * manager approves (CEO / finance from the value limit), never their own act; managers, finance,
 * accountants, management and purchasing read them; purchasing follows supplier claims.
 */
class StockOffActPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'stock_off.view');
    }

    public function view(User $user, StockOffAct $act): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Whether the act's money (value of the written-off goods) is shown; warehouse staff see quantities only.
     */
    public function viewValue(User $user): bool
    {
        return PermissionMap::allows($user, 'stock_off.view_value');
    }

    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'stock_off.create');
    }

    public function update(User $user, StockOffAct $act): bool
    {
        return $act->status->isEditable() && $this->create($user);
    }

    public function transition(User $user, StockOffAct $act, StockOffStatus $to): bool
    {
        return TransitionStockOffAct::allows($user, $act, $to);
    }

    public function addEvidence(User $user, StockOffAct $act): bool
    {
        return $act->status->isOpen() && ($this->create($user) || PermissionMap::allows($user, 'stock_off.approve'));
    }

    public function manageClaim(User $user, StockOffAct $act): bool
    {
        return $act->status !== StockOffStatus::Cancelled && $act->goods_receipt_id !== null && PermissionMap::allows($user, 'stock_off.claim');
    }

    public function print(User $user, StockOffAct $act): bool
    {
        return $this->view($user, $act);
    }

    public function delete(User $user, StockOffAct $act): bool
    {
        return false;
    }
}
