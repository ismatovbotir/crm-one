<?php

namespace App\Actions\Accounting;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use Illuminate\Support\Facades\DB;

/**
 * Prepayment share and deferred payment days of an order (Spec 06 4.3 `orders.amend_schedule`):
 * accountant, manager or finance, before closing. The schedule is regenerated (old items
 * superseded); coverage by existing allocations is recomputed FIFO.
 */
class UpdatePaymentTerms
{
    public function __construct(protected PaymentScheduleGenerator $schedule, protected OrderPayments $orderPayments) {}

    public function handle(Order $order, User $user, ?int $prepaymentBp, ?int $paymentTermsDays): Order
    {
        if ($prepaymentBp !== null && ($prepaymentBp < 0 || $prepaymentBp > 10000)) {
            throw new AccountingActionException('prepayment_invalid');
        }

        return DB::transaction(function () use ($order, $user, $prepaymentBp, $paymentTermsDays): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Closed], true)) {
                throw new AccountingActionException('order_not_payable');
            }

            $order->forceFill(['prepayment_bp' => $prepaymentBp ?: null, 'payment_terms_days' => $paymentTermsDays])->save();

            if ($order->status !== OrderStatus::New) {
                $this->schedule->generate($order, $user);
                $this->orderPayments->recompute($order);
            }

            return $order;
        });
    }
}
