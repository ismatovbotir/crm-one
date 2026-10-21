<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Reverses one allocation with a reason (Spec 06 BR-AC.10): a negative row pointing at the
 * original, dated today in the open period; the payment itself is untouched.
 */
class DeallocatePayment
{
    public function __construct(protected AccountingPeriods $periods, protected Ledger $ledger, protected RecomputeAfterPaymentChange $recompute) {}

    public function handle(PaymentAllocation $allocation, ?User $user, string $reason, bool $skipPeriodCheck = false): PaymentAllocation
    {
        return DB::transaction(function () use ($allocation, $user, $reason, $skipPeriodCheck): PaymentAllocation {
            $allocation = PaymentAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $today = AccountingPeriods::today();

            if (! $skipPeriodCheck) {
                $this->periods->assertOpen($today);
            }

            if ($allocation->isReversal() || $allocation->reversal()->exists()) {
                throw new AccountingActionException('already_reversed');
            }

            $reversal = PaymentAllocation::query()->create([
                'payment_id' => $allocation->payment_id,
                'order_id' => $allocation->order_id,
                'invoice_id' => $allocation->invoice_id,
                'amount_minor' => -$allocation->amount_minor,
                'order_amount_minor' => -$allocation->order_amount_minor,
                'fx_rate' => $allocation->fx_rate,
                'fx_rate_date' => $allocation->fx_rate_date?->toDateString(),
                'fx_source' => $allocation->fx_source,
                'reverses_id' => $allocation->id,
                'reason' => $reason,
                'period' => AccountingPeriods::of($today),
                'created_by' => $user?->id,
                'created_at' => now(),
            ]);

            $order = $allocation->order;
            $this->ledger->write(EntryType::Deallocation, -$allocation->order_amount_minor, $order->currency, $today, ['customer_id' => $order->customer_id, 'order_id' => $order->id, 'payment_id' => $allocation->payment_id, 'allocation_id' => $reversal->id], $user, 'crm', ['reason' => $reason]);

            if ($allocation->invoice_id !== null) {
                $invoice = Invoice::query()->find($allocation->invoice_id);
                $invoice?->forceFill(['paid_minor' => max(0, $invoice->paid_minor - $allocation->order_amount_minor)])->saveQuietly();
            }

            $this->recompute->handle(Payment::query()->findOrFail($allocation->payment_id));

            return $reversal;
        });
    }
}
