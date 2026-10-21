<?php

namespace App\Actions\Orders;

use App\Enums\HoldReason;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderHolds;

/**
 * Release a hold on one order with a reason (Spec 06 6.2): logged in the activity log and shown
 * as "Released by <user>: <reason>". Who may release which hold is OrderPolicy::overrideHold.
 */
class OverrideHold
{
    public function __construct(protected OrderHolds $holds) {}

    public function handle(Order $order, HoldReason $reason, User $user, string $text): Order
    {
        $order = $order->fresh();

        if ($order->status->isFinal()) {
            throw new OrderActionException('order_final');
        }

        if (! $this->holds->isActive($order, $reason)) {
            throw new OrderActionException('hold_not_active');
        }

        $this->holds->override($order, $reason, $user, $text);
        $order->save();

        return $order;
    }
}
