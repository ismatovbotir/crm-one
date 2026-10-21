<?php

namespace App\Actions\Accounting;

use App\Enums\MatchState;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\OneC\Inbound\OneCSyncContext;
use Illuminate\Support\Facades\DB;

/**
 * Accountant queue actions for payments from 1C (Spec 06 9.3, AC-36/37):
 *  - link an unmatched payment to a customer (allocation then allowed);
 *  - possible duplicate → "same payment": the CRM-recorded payment takes over the 1C identity and
 *    is confirmed, the 1C row (never counted) is voided as merged; "different" → counts.
 */
class ResolvePayment
{
    public function __construct(protected RecomputeAfterPaymentChange $recompute) {}

    public function linkCustomer(Payment $payment, Partner $customer, User $user): Payment
    {
        if ($payment->customer_id !== null) {
            throw new AccountingActionException('payment_already_matched');
        }

        $payment->forceFill(['customer_id' => $customer->id])->save();
        $payment->logActivity('payment_customer_linked', ['customer' => $customer->name]);

        return $payment;
    }

    public function markSame(Payment $duplicate, User $user): Payment
    {
        return DB::transaction(function () use ($duplicate, $user): Payment {
            $duplicate = Payment::query()->lockForUpdate()->findOrFail($duplicate->id);
            $original = $duplicate->duplicate_of_id !== null ? Payment::query()->lockForUpdate()->find($duplicate->duplicate_of_id) : null;

            if ($duplicate->match_state !== MatchState::PossibleDuplicate || $original === null) {
                throw new AccountingActionException('not_possible_duplicate');
            }

            $guid = $duplicate->onec_guid;
            $duplicate->forceFill(['onec_guid' => null, 'status' => PaymentStatus::Voided, 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => 'merged_duplicate', 'match_state' => MatchState::Linked])->save();
            OneCSyncContext::run(fn () => $original->forceFill([
                'onec_guid' => $guid, 'onec_number' => $duplicate->onec_number, 'onec_acked_at' => now(), 'match_state' => MatchState::Linked,
                'status' => $original->status->canTransitionTo(PaymentStatus::Confirmed) ? PaymentStatus::Confirmed : $original->status,
            ])->save());
            $this->recompute->handle($original);

            return $original;
        });
    }

    public function markDistinct(Payment $duplicate, User $user): Payment
    {
        if ($duplicate->match_state !== MatchState::PossibleDuplicate) {
            throw new AccountingActionException('not_possible_duplicate');
        }

        $duplicate->forceFill(['match_state' => MatchState::Distinct])->save();
        $this->recompute->handle($duplicate);

        return $duplicate;
    }
}
