<?php

namespace App\Services\Accounting;

use App\Enums\MatchState;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\ScheduleItemKind;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Partner;
use App\Models\PaymentAllocation;
use App\Models\PaymentScheduleItem;
use App\Support\BusinessSettings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Order payment state (Spec 06 6.3, DEC-5, BR-AC.18): `paid_minor` = Σ counted allocations in the
 * order currency; schedule items are covered FIFO by due date (undated items last); overdue wins
 * over unpaid/partially paid.
 */
class OrderPayments
{
    /**
     * Allocations of payments that count (DEC-13), not unresolved duplicates.
     *
     * @return Builder<PaymentAllocation>
     */
    public static function countedAllocations(): Builder
    {
        return PaymentAllocation::query()->whereHas('payment', fn (Builder $payment) => $payment
            ->where('kind', PaymentKind::Receipt)
            ->whereIn('status', PaymentStatus::counting())
            ->where(fn (Builder $match) => $match->whereNull('match_state')->orWhere('match_state', '!=', MatchState::PossibleDuplicate))
            // Unposted 1C payments do not count (Spec 06 9.3 step 6).
            ->where(fn (Builder $posted) => $posted->whereNull('onec_posted')->orWhere('onec_posted', true)));
    }

    public function paidMinor(Order $order): int
    {
        return (int) self::countedAllocations()->where('order_id', $order->id)->sum('order_amount_minor');
    }

    /** Σ active allocations of any non-voided payment (caps new allocations, BR-AC.9). */
    public function allocatedMinor(Order $order): int
    {
        return (int) PaymentAllocation::query()->where('order_id', $order->id)
            ->whereHas('payment', fn (Builder $payment) => $payment->whereNotIn('status', [PaymentStatus::Voided, PaymentStatus::Rejected]))
            ->sum('order_amount_minor');
    }

    /** Recomputes and stores `paid_minor` and `payment_status`. */
    public function recompute(Order $order): Order
    {
        $order->paid_minor = $this->paidMinor($order);
        $order->payment_status = $this->status($order);

        if ($order->isDirty(['paid_minor', 'payment_status'])) {
            $order->save();
        }

        return $order;
    }

    public function status(Order $order): OrderPaymentStatus
    {
        if ($order->gross_minor > 0 && $order->paid_minor >= $order->gross_minor) {
            return OrderPaymentStatus::Paid;
        }

        if ($this->isOverdue($order)) {
            return OrderPaymentStatus::Overdue;
        }

        return $order->paid_minor > 0 ? OrderPaymentStatus::PartiallyPaid : OrderPaymentStatus::Unpaid;
    }

    /**
     * FIFO coverage of the active schedule by the counted paid amount.
     *
     * @return list<array{item: PaymentScheduleItem, covered: int, outstanding: int, days_overdue: int|null}>
     */
    public function coverage(Order $order): array
    {
        $remaining = $order->paid_minor;
        $today = AccountingPeriods::today();
        $grace = BusinessSettings::int('accounting.overdue_grace_days');
        $rows = [];

        $items = $order->scheduleItems()->get()->sortBy(fn (PaymentScheduleItem $item): string => ($item->due_on?->format('Y-m-d') ?? '9999-12-31').sprintf('%05d', $item->position));

        foreach ($items as $item) {
            $covered = min($remaining, $item->amount_minor);
            $remaining -= $covered;
            $outstanding = $item->amount_minor - $covered;
            $daysOverdue = null;

            if ($outstanding > 0 && $item->due_on !== null) {
                $due = $item->due_on->toDateString();
                $days = (int) CarbonDays::between($due, $today->toDateString());
                $daysOverdue = $days > $grace ? $days : null;
            }

            $rows[] = ['item' => $item, 'covered' => $covered, 'outstanding' => $outstanding, 'days_overdue' => $daysOverdue];
        }

        return $rows;
    }

    public function isOverdue(Order $order): bool
    {
        if (in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled], true)) {
            return false;
        }

        return collect($this->coverage($order))->contains(fn (array $row): bool => $row['days_overdue'] !== null);
    }

    /** Every prepayment item fully covered (no prepayment = covered). */
    public function prepaymentCovered(Order $order): bool
    {
        return ! collect($this->coverage($order))->contains(fn (array $row): bool => $row['item']->kind === ScheduleItemKind::Prepayment && $row['outstanding'] > 0);
    }

    /** The customer has an item overdue by more than $days on another active order (6.2 overdue hold). */
    public function customerOverdueBeyond(Partner $customer, int $days, ?Order $except = null): bool
    {
        $orders = Order::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Closed, OrderStatus::Cancelled])
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->id))
            ->get();

        foreach ($orders as $order) {
            foreach ($this->coverage($order) as $row) {
                if ($row['days_overdue'] !== null && $row['days_overdue'] > $days) {
                    return true;
                }
            }
        }

        return false;
    }
}
