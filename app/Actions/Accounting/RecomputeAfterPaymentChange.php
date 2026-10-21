<?php

namespace App\Actions\Accounting;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Accounting\OrderPayments;

/**
 * Recomputes `paid_minor` / `payment_status` of every order a payment touches (BR-AC.18) and the
 * payment's own allocated cache. Call inside the transaction of the change.
 */
class RecomputeAfterPaymentChange
{
    public function __construct(protected OrderPayments $orderPayments) {}

    public function handle(Payment $payment): void
    {
        $payment->allocated_minor = max(0, (int) $payment->allocations()->sum('amount_minor'));

        if ($payment->isDirty('allocated_minor')) {
            $payment->saveQuietly();
        }

        $orderIds = $payment->allocations()->reorder()->distinct()->pluck('order_id');

        foreach (Order::query()->whereIn('id', $orderIds)->get() as $order) {
            $this->orderPayments->recompute($order);
        }
    }
}
