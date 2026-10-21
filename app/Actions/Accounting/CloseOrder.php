<?php

namespace App\Actions\Accounting;

use App\Actions\Orders\TransitionOrder;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\OneC\Outbox\OneCOutbox;
use Illuminate\Support\Facades\DB;

/**
 * invoiced → closed (Spec 06 6.1, BR-OR.11): paid, agreement signed/active (or not required /
 * framework), no addendum pending. Retail (section 25): delivered → closed when paid and the
 * sales receipt is issued (no tax document). Manual by accountant/finance/manager, nightly when
 * `accounting.auto_close_orders`. Outbox `order.closed`.
 */
class CloseOrder
{
    public function __construct(protected TransitionOrder $transition, protected OneCOutbox $outbox) {}

    public function blocker(Order $order): ?string
    {
        $retail = $order->sale_type->isRetail();

        return match (true) {
            $retail && ! in_array($order->status, [OrderStatus::Delivered, OrderStatus::Invoiced], true) => 'close_not_delivered',
            ! $retail && $order->status !== OrderStatus::Invoiced => 'close_not_invoiced',
            $order->payment_status !== OrderPaymentStatus::Paid => 'close_not_paid',
            $retail && ! $order->hasIssuedReceipt() => 'close_no_receipt',
            ! $order->hasBindingAgreement() => 'close_no_agreement',
            $order->addendum_pending => 'close_addendum_pending',
            default => null,
        };
    }

    public function handle(Order $order, ?User $user): Order
    {
        return DB::transaction(function () use ($order, $user): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $blocker = $this->blocker($order);

            if ($blocker !== null) {
                throw new AccountingActionException($blocker);
            }

            $this->transition->handle($order, OrderStatus::Closed, $user, $user === null ? 'auto_close' : null, ['closed_at' => now(), 'closed_by' => $user?->id]);
            $this->outbox->publishOrder('order.closed', $order);

            return $order;
        });
    }

    /** Nightly auto-close (idempotent). */
    public function closeEligible(): int
    {
        $closed = 0;

        Order::query()->where('payment_status', OrderPaymentStatus::Paid)
            ->where(fn ($query) => $query->where('status', OrderStatus::Invoiced)->orWhere(fn ($retail) => $retail->where('status', OrderStatus::Delivered)->where('sale_type', 'retail')))
            ->each(function (Order $order) use (&$closed): void {
                if ($this->blocker($order) === null) {
                    $this->handle($order, null);
                    $closed++;
                }
            });

        return $closed;
    }
}
