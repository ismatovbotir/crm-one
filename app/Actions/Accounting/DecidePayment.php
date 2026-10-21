<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\OrderPayments;
use App\Services\OneC\Outbox\OneCOutbox;
use Illuminate\Support\Facades\DB;

/**
 * Second-person decision on a payment or refund waiting for approval (Spec 06 SoD-3/4, AC-24):
 * the approver is never the recorder. Approve → `recorded` (counts) + outbox; reject → `rejected`
 * with a reason (never counted).
 */
class DecidePayment
{
    public function __construct(protected AccountingPeriods $periods, protected Ledger $ledger, protected OneCOutbox $outbox, protected RecomputeAfterPaymentChange $recompute) {}

    public function approve(Payment $payment, User $approver): Payment
    {
        return DB::transaction(function () use ($payment, $approver): Payment {
            $payment = $this->locked($payment, $approver);
            $this->periods->assertOpen($payment->paid_on);

            if ($payment->kind === PaymentKind::Refund) {
                $original = Payment::query()->lockForUpdate()->findOrFail($payment->refund_of_payment_id);

                if ($payment->amount_minor > $original->unallocatedMinor()) {
                    throw new AccountingActionException('refund_exceeds_balance');
                }

                $original->forceFill(['refunded_minor' => $original->refunded_minor + $payment->amount_minor])->save();
            }

            $payment->forceFill(['status' => PaymentStatus::Recorded, 'approved_by' => $approver->id, 'approved_at' => now()])->save();

            $this->ledger->write($payment->kind === PaymentKind::Refund ? EntryType::RefundRecorded : EntryType::PaymentApproved, $payment->kind === PaymentKind::Refund ? -$payment->amount_minor : 0, $payment->currency, $payment->paid_on, ['customer_id' => $payment->customer_id, 'payment_id' => $payment->id], $approver);
            $this->outbox->publishPayment($payment->kind === PaymentKind::Refund ? 'payment.refund_recorded' : 'payment.recorded', $payment);

            // Spec 06 section 29: a payment for an order is allocated to it once approved.
            // DBA review M-5: lock the order first, then read its open amount, so two approvals for
            // the same order serialize and the second sees the first allocation.
            if ($payment->kind === PaymentKind::Receipt && $payment->planned_order_id !== null) {
                $order = Order::query()->lockForUpdate()->find($payment->planned_order_id);
                $open = $order !== null ? $order->gross_minor - app(OrderPayments::class)->allocatedMinor($order) : 0;

                if ($order !== null && $open > 0 && $payment->currency === $order->currency) {
                    app(AllocatePayment::class)->handle($payment, $order, min($open, $payment->amount_minor), $approver);
                }
            }

            return $payment;
        });
    }

    public function reject(Payment $payment, User $approver, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $approver, $reason): Payment {
            $payment = $this->locked($payment, $approver);
            $payment->forceFill(['status' => PaymentStatus::Rejected, 'rejected_by' => $approver->id, 'rejected_at' => now(), 'reject_reason' => $reason])->save();
            $this->recompute->handle($payment);

            return $payment;
        });
    }

    protected function locked(Payment $payment, User $approver): Payment
    {
        $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        if ($payment->status !== PaymentStatus::PendingApproval) {
            throw new AccountingActionException('not_pending_approval');
        }

        if ($payment->recorded_by === $approver->id) {
            throw new AccountingActionException('self_approval');
        }

        return $payment;
    }
}
