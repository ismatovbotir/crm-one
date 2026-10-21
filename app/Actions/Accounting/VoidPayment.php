<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\PaymentKind;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use App\Services\OneC\Outbox\OneCOutbox;
use Illuminate\Support\Facades\DB;

/**
 * Void instead of delete (Spec 06 DEC-3, BR-AC.11, AC-27): the accountant requests with a reason,
 * a different `payments.approve` user (not the requester, not the recorder) approves → `voided`,
 * every active allocation reversed, ledger reversal dated today when the original period is
 * locked, outbox `payment.voided`. 1C-sourced payments cannot be voided in the CRM (SoD-7).
 */
class VoidPayment
{
    public function __construct(protected AccountingPeriods $periods, protected Ledger $ledger, protected OneCOutbox $outbox, protected DeallocatePayment $deallocate, protected RecomputeAfterPaymentChange $recompute) {}

    public function request(Payment $payment, User $user, string $reason): Payment
    {
        $payment = $payment->fresh();

        if ($payment->source === PaymentSource::Onec) {
            throw new AccountingActionException('onec_payment_locked');
        }

        if (! $payment->status->canTransitionTo(PaymentStatus::Voided) || $payment->void_requested_at !== null) {
            throw new AccountingActionException('not_voidable');
        }

        $payment->forceFill(['void_requested_by' => $user->id, 'void_requested_at' => now(), 'void_reason' => $reason])->save();

        return $payment;
    }

    public function approve(Payment $payment, User $approver): Payment
    {
        return DB::transaction(function () use ($payment, $approver): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->void_requested_at === null || ! $payment->status->canTransitionTo(PaymentStatus::Voided)) {
                throw new AccountingActionException('not_voidable');
            }

            if (in_array($approver->id, [$payment->void_requested_by, $payment->recorded_by], true)) {
                throw new AccountingActionException('self_approval');
            }

            if ($payment->kind === PaymentKind::Receipt && $payment->refunded_minor > 0) {
                throw new AccountingActionException('void_has_refunds');
            }

            $wasCounted = in_array($payment->status, [PaymentStatus::Recorded, PaymentStatus::Confirmed], true);
            $open = ! $this->periods->isLocked($payment->period);

            PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('reverses_id')->whereDoesntHave('reversal')->get()
                ->each(fn (PaymentAllocation $allocation) => $this->deallocate->handle($allocation, $approver, 'payment_voided', true));

            $payment->forceFill(['status' => PaymentStatus::Voided, 'voided_by' => $approver->id, 'voided_at' => now()])->save();

            $occurredOn = $open ? $payment->paid_on : AccountingPeriods::today();
            $amount = $payment->kind === PaymentKind::Refund ? $payment->amount_minor : -$payment->amount_minor;
            $this->ledger->write(EntryType::PaymentVoided, $amount, $payment->currency, $occurredOn, ['customer_id' => $payment->customer_id, 'payment_id' => $payment->id], $approver, 'crm', ['reason' => $payment->void_reason]);

            if ($payment->kind === PaymentKind::Refund && $wasCounted) {
                $original = Payment::query()->lockForUpdate()->find($payment->refund_of_payment_id);
                $original?->forceFill(['refunded_minor' => max(0, $original->refunded_minor - $payment->amount_minor)])->save();
            }

            if ($wasCounted) {
                $this->outbox->publishPayment('payment.voided', $payment);
            }

            $this->recompute->handle($payment);

            return $payment;
        });
    }

    public function cancelRequest(Payment $payment, User $user): Payment
    {
        $payment = $payment->fresh();
        $payment->forceFill(['void_requested_by' => null, 'void_requested_at' => null, 'void_reason' => null])->save();

        return $payment;
    }
}
