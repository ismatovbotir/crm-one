<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;

/**
 * Spec 06 section 31 (user decision 2026-10-20): an order needs no separate confirmation. Right
 * after it is created it is started with the confirmation rules (stock check with shortages
 * flagged as backorder automatically, reservations, payment schedule, `order.confirmed` available
 * for 1C to pull). Only a blocking hold keeps it in `new` (customer blocked, credit limit,
 * overdue, manual hold): the hold is stored and visible, and a manager or finance releases it,
 * then "Start order" runs this again.
 */
class StartOrder
{
    public function __construct(protected ConfirmOrder $confirm) {}

    public function handle(Order $order, ?User $user): Order
    {
        $order = $order->fresh();

        if ($order->status !== OrderStatus::New) {
            return $order;
        }

        $actor = $user ?? $order->owner;

        if ($actor === null) {
            return $order;
        }

        try {
            return $this->confirm->handle($order, $actor, true);
        } catch (OrderActionException) {
            // A blocking hold (stored on the order) or a refused shortage: the order stays `new`.
            return $order->fresh();
        }
    }
}
