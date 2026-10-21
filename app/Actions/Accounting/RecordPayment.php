<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use App\Services\Attachments\StoreAttachment;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Support\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Records a received payment (Spec 06 BR-AC.5/6, SoD-4): validated fields, open period, duplicate
 * guard (refused unless confirmed), proof above the threshold for bank transfers; below the
 * approval threshold it is `recorded` (counts, outbox `payment.recorded`), at or above it waits
 * `pending_approval`. Ledger entry in the same transaction.
 */
class RecordPayment
{
    public function __construct(protected AccountingPeriods $periods, protected Ledger $ledger, protected OneCOutbox $outbox, protected StoreAttachment $attachments) {}

    /**
     * @param  array{customer_id: int, amount_minor: int, currency: string, paid_on: string, method: string, company_bank_account_id?: int|null, payer_name: string, payer_tax_id?: string|null, payer_account?: string|null, reference_number?: string|null, purpose?: string|null, note?: string|null}  $data
     */
    public function handle(array $data, User $user, ?UploadedFile $proof = null, bool $confirmDuplicate = false): Payment
    {
        $customer = Customer::withTrashed()->findOrFail($data['customer_id']);
        $method = PaymentMethod::from($data['method']);
        $currency = strtoupper($data['currency']);
        $paidOn = CarbonImmutable::parse($data['paid_on'])->toDateString();
        $amount = (int) $data['amount_minor'];

        if ($amount <= 0) {
            throw new AccountingActionException('amount_positive');
        }

        if ($paidOn > AccountingPeriods::today()->toDateString()) {
            throw new AccountingActionException('date_in_future');
        }

        $this->periods->assertOpen($paidOn);

        if ($method->needsBankDetails()) {
            $account = CompanyBankAccount::query()->whereKey($data['company_bank_account_id'] ?? 0)->where('is_active', true)->first();

            if ($account === null || $account->currency !== $currency) {
                throw new AccountingActionException('bank_account_required');
            }

            if (blank($data['reference_number'] ?? null)) {
                throw new AccountingActionException('reference_required');
            }
        }

        $proofThreshold = BusinessSettings::array('accounting.proof_required_above_minor')[$currency] ?? null;

        if ($method === PaymentMethod::BankTransfer && $proofThreshold !== null && $amount >= (int) $proofThreshold && $proof === null) {
            throw new AccountingActionException('proof_required');
        }

        if (! $confirmDuplicate && ($duplicate = $this->duplicateOf($customer, $amount, $currency, $data['reference_number'] ?? null, $paidOn)) !== null) {
            throw new AccountingActionException('possible_duplicate', ['number' => $duplicate->number]);
        }

        return DB::transaction(function () use ($data, $user, $proof, $customer, $method, $currency, $paidOn, $amount): Payment {
            $threshold = BusinessSettings::array('accounting.payment_approval_threshold_minor')[$currency] ?? null;
            $status = $threshold !== null && $amount >= (int) $threshold ? PaymentStatus::PendingApproval : PaymentStatus::Recorded;
            $year = (int) AccountingPeriods::today()->year;

            $payment = Payment::query()->create([
                'number' => NumberSequence::format('PM', $year, NumberSequence::next('PM', $year)),
                'direction' => 'incoming',
                'kind' => PaymentKind::Receipt,
                'status' => $status,
                'source' => PaymentSource::Crm,
                'customer_id' => $customer->id,
                'amount_minor' => $amount,
                'currency' => $currency,
                'paid_on' => $paidOn,
                'method' => $method,
                'company_bank_account_id' => $method->needsBankDetails() ? $data['company_bank_account_id'] : null,
                'payer_name' => trim($data['payer_name']),
                'payer_tax_id' => $data['payer_tax_id'] ?? null,
                'payer_account' => $data['payer_account'] ?? null,
                'reference_number' => filled($data['reference_number'] ?? null) ? trim((string) $data['reference_number']) : null,
                'purpose' => $data['purpose'] ?? null,
                'note' => $data['note'] ?? null,
                'period' => AccountingPeriods::of($paidOn),
                'recorded_by' => $user->id,
            ]);

            if ($proof !== null) {
                $attachment = $this->attachments->handle($proof, $payment, 'payment_proof', $user, 10240, 'proof');
                $payment->forceFill(['proof_attachment_id' => $attachment->id])->save();
            }

            $this->ledger->write(EntryType::PaymentRecorded, $amount, $currency, $paidOn, ['customer_id' => $customer->id, 'payment_id' => $payment->id], $user, 'crm', ['status' => $status->value]);

            if ($status === PaymentStatus::Recorded) {
                $this->outbox->publishPayment('payment.recorded', $payment);
            }

            return $payment;
        });
    }

    public function duplicateOf(Partner $customer, int $amount, string $currency, ?string $reference, string $paidOn): ?Payment
    {
        return Payment::query()
            ->where('customer_id', $customer->id)
            ->where('amount_minor', $amount)
            ->where('currency', $currency)
            ->whereDate('paid_on', $paidOn)
            ->when(filled($reference), fn ($query) => $query->where('reference_number', trim((string) $reference)), fn ($query) => $query->whereNull('reference_number'))
            ->whereNotIn('status', [PaymentStatus::Voided, PaymentStatus::Rejected])
            ->first();
    }
}
