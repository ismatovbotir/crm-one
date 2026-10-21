<?php

namespace App\Actions\Accounting;

use App\Actions\Orders\TransitionOrder;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Support\Quantity;

/**
 * delivered → invoiced (Spec 06 6.1): every line's delivered quantity is covered by registered,
 * not voided tax documents.
 */
class MarkOrderInvoiced
{
    public function __construct(protected TransitionOrder $transition) {}

    public function handle(Order $order, ?User $user): void
    {
        if ($order->status !== OrderStatus::Delivered) {
            return;
        }

        $complete = $order->lines()->get()->every(fn ($line): bool => bccomp((string) $line->invoiced_qty, (string) $line->delivered_qty, Quantity::SCALE) >= 0);

        if ($complete) {
            $this->transition->handle($order, OrderStatus::Invoiced, $user, null, ['invoiced_at' => now()]);
        }
    }
}
