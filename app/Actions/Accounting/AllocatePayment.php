<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\MatchState;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\FxRates;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\OrderPayments;
use App\Services\Notifications\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Allocates part of a payment to an order of the same customer (Spec 06 BR-AC.7/8/9, AC-25/26):
 * open period (today), Σ ≤ payment amount, never above the order's remaining gross, invoice of the
 * same order, FX rate when currencies differ (half-up). Append-only row + ledger entry; caches
 * and order payment status recomputed in the same transaction. All ids re-checked server-side.
 */
class AllocatePayment
{
    public function __construct(
        protected AccountingPeriods $periods,
        protected Ledger $ledger,
        protected OrderPayments $orderPayments,
        protected RecomputeAfterPaymentChange $recompute,
    ) {}

    public function handle(Payment $payment, Order $order, int $amountMinor, ?User $user, ?Invoice $invoice = null, ?string $fxRate = null, string $fxSource = 'cbu_manual'): PaymentAllocation
    {
        if ($amountMinor <= 0) {
            throw new AccountingActionException('amount_positive');
        }

        return DB::transaction(function () use ($payment, $order, $amountMinor, $user, $invoice, $fxRate, $fxSource): PaymentAllocation {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $today = AccountingPeriods::today();
            $this->periods->assertOpen($today);

            if ($payment->kind !== PaymentKind::Receipt || ! $payment->status->isAllocatable()) {
                throw new AccountingActionException('payment_not_allocatable');
            }

            if ($payment->match_state === MatchState::PossibleDuplicate) {
                throw new AccountingActionException('payment_possible_duplicate');
            }

            if ($payment->customer_id === null) {
                throw new AccountingActionException('payment_unmatched');
            }

            if ($payment->customer_id !== $order->customer_id) {
                throw new AccountingActionException('customer_mismatch');
            }

            if (in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled], true)) {
                throw new AccountingActionException('order_not_payable');
            }

            if ($invoice !== null && $invoice->order_id !== $order->id) {
                throw new AccountingActionException('invoice_mismatch');
            }

            if ($amountMinor > $payment->unallocatedMinor()) {
                throw new AccountingActionException('exceeds_payment', ['amount' => $payment->money($payment->unallocatedMinor())->format()]);
            }

            if ($payment->currency === $order->currency) {
                $rate = null;
                $orderAmount = $amountMinor;
            } else {
                $rate = $fxRate ?? app(FxRates::class)->propose($payment->currency, $order->currency, $payment->paid_on);

                if ($rate === null || ! is_numeric($rate) || bccomp($rate, '0', 8) <= 0) {
                    throw new AccountingActionException('fx_rate_required');
                }

                $orderAmount = FxRates::convert($amountMinor, $rate, $payment->currency, $order->currency);
            }

            $remaining = $order->gross_minor - $this->orderPayments->allocatedMinor($order);

            if ($orderAmount > $remaining) {
                throw new AccountingActionException('exceeds_order', ['amount' => $order->money(max(0, $remaining))->format()]);
            }

            $allocation = PaymentAllocation::query()->create([
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'invoice_id' => $invoice?->id,
                'amount_minor' => $amountMinor,
                'order_amount_minor' => $orderAmount,
                'fx_rate' => $rate,
                'fx_rate_date' => $rate !== null ? $payment->paid_on->toDateString() : null,
                'fx_source' => $rate !== null ? $fxSource : null,
                'period' => AccountingPeriods::of($today),
                'created_by' => $user?->id,
                'created_at' => now(),
            ]);

            $this->ledger->write(EntryType::Allocation, $orderAmount, $order->currency, $today, ['customer_id' => $order->customer_id, 'order_id' => $order->id, 'invoice_id' => $invoice?->id, 'payment_id' => $payment->id, 'allocation_id' => $allocation->id], $user, 'crm', $rate !== null ? ['fx_rate' => $rate, 'payment_amount' => $amountMinor, 'payment_currency' => $payment->currency] : null);

            if ($invoice !== null) {
                $invoice->forceFill(['paid_minor' => $invoice->paid_minor + $orderAmount])->saveQuietly();
            }

            $this->recompute->handle($payment);

            DB::afterCommit(fn () => app(Notifier::class)->paymentReceived($order, $amountMinor, $user));

            return $allocation;
        });
    }
}
