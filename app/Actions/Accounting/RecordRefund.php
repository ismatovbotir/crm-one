<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\NumberSequence;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Refund (Spec 06 BR-AC.11, AC-28): an outgoing payment (`RF-`) against the unallocated balance
 * of an incoming payment; always `pending_approval` (a second person approves via DecidePayment).
 */
class RecordRefund
{
    public function __construct(protected AccountingPeriods $periods, protected Ledger $ledger) {}

    /**
     * @param  array{amount_minor: int, paid_on: string, method: string, company_bank_account_id?: int|null, reference_number?: string|null, note?: string|null}  $data
     */
    public function handle(Payment $original, array $data, User $user): Payment
    {
        return DB::transaction(function () use ($original, $data, $user): Payment {
            $original = Payment::query()->lockForUpdate()->findOrFail($original->id);
            $amount = (int) $data['amount_minor'];

            if ($original->kind !== PaymentKind::Receipt || ! $original->status->isAllocatable() || $original->customer_id === null) {
                throw new AccountingActionException('payment_not_allocatable');
            }

            $pending = (int) Payment::query()->where('refund_of_payment_id', $original->id)->where('status', PaymentStatus::PendingApproval)->sum('amount_minor');

            if ($amount <= 0 || $amount > $original->unallocatedMinor() - $pending) {
                throw new AccountingActionException('refund_exceeds_balance');
            }

            $this->periods->assertOpen($data['paid_on']);
            $year = (int) AccountingPeriods::today()->year;
            $method = PaymentMethod::from($data['method']);

            $refund = Payment::query()->create([
                'number' => NumberSequence::format('RF', $year, NumberSequence::next('RF', $year)),
                'direction' => 'outgoing',
                'kind' => PaymentKind::Refund,
                'status' => PaymentStatus::PendingApproval,
                'source' => PaymentSource::Crm,
                'customer_id' => $original->customer_id,
                'amount_minor' => $amount,
                'currency' => $original->currency,
                'paid_on' => $data['paid_on'],
                'method' => $method,
                'company_bank_account_id' => $method->needsBankDetails() ? ($data['company_bank_account_id'] ?? $original->company_bank_account_id) : null,
                'payer_name' => $original->payer_name,
                'payer_tax_id' => $original->payer_tax_id,
                'reference_number' => $data['reference_number'] ?? null,
                'note' => $data['note'] ?? null,
                'refund_of_payment_id' => $original->id,
                'period' => AccountingPeriods::of($data['paid_on']),
                'recorded_by' => $user->id,
            ]);

            $this->ledger->write(EntryType::RefundRecorded, 0, $refund->currency, $refund->paid_on, ['customer_id' => $refund->customer_id, 'payment_id' => $refund->id], $user, 'crm', ['status' => 'pending_approval', 'refund_of' => $original->number]);

            return $refund;
        });
    }
}
