<?php

namespace App\Services\OneC\Payments;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\DeallocatePayment;
use App\Actions\Accounting\RecomputeAfterPaymentChange;
use App\Enums\EntryType;
use App\Enums\MatchState;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\Inbound\OneCSyncContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies one payment pushed by 1C (Spec 06 9.3), 1C being the book of record:
 *  1. `crm_id` → the CRM payment 1C created from the outbox: link, confirm, correct differences;
 *  2. known 1C GUID → update (older `updated_at` ignored);
 *  3. exact match to an unconfirmed CRM payment → link; fuzzy match → new payment marked
 *     `possible_duplicate` (not counted until resolved);
 *  4. otherwise a new confirmed payment, allocated per `allocations[]` when the orders fit;
 *  5. unknown customer → unmatched (no customer), allocation refused until linked;
 *  6. deletion mark → voided (`deleted_in_1c`), allocations reversed; unposted → does not count;
 *  7. dates in a locked period are accepted and flagged.
 */
class PaymentInboundProcessor
{
    public function __construct(
        protected BatchValidator $validator,
        protected Ledger $ledger,
        protected AccountingPeriods $periods,
        protected AllocatePayment $allocate,
        protected DeallocatePayment $deallocate,
        protected RecomputeAfterPaymentChange $recompute,
    ) {}

    /**
     * @return array{index: int, id: string|null, result: string, crm_id?: string|null, errors?: list<array{field: string|null, code: string}>}
     */
    public function process(mixed $record, int $index): array
    {
        $errors = $this->validate($record);
        $id = is_array($record) && is_string($record['id'] ?? null) ? strtolower($record['id']) : null;

        if ($errors !== []) {
            return ['index' => $index, 'id' => $id, 'result' => 'failed', 'errors' => $errors];
        }

        try {
            return OneCSyncContext::run(fn (): array => DB::transaction(fn (): array => ['index' => $index, 'id' => $id, ...$this->apply($record)]));
        } catch (Throwable $exception) {
            report($exception);

            return ['index' => $index, 'id' => $id, 'result' => 'failed', 'errors' => [['field' => null, 'code' => 'processing_error']]];
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{result: string, crm_id?: string|null}
     */
    protected function apply(array $record): array
    {
        $guid = strtolower($record['id']);
        $currency = strtoupper($record['currency']);
        $amount = Money::fromMajor((string) $record['amount'], $currency)->amount;
        $paidOn = CarbonImmutable::parse($record['paid_on'])->toDateString();
        $updatedAt = CarbonImmutable::parse($record['updated_at'])->utc();
        $customer = $this->customer($record);
        $values = [
            'amount_minor' => $amount, 'currency' => $currency, 'paid_on' => $paidOn, 'customer_id' => $customer?->id,
            'onec_number' => is_string($record['number'] ?? null) ? mb_substr($record['number'], 0, 40) : null,
            'onec_posted' => (bool) ($record['posted'] ?? true),
            'onec_version' => is_string($record['version'] ?? null) ? mb_substr($record['version'], 0, 64) : null,
            'onec_updated_at' => $updatedAt,
            'posted_in_locked_period' => $this->periods->isLocked(AccountingPeriods::of($paidOn)),
        ];

        $payment = null;

        if (is_string($record['crm_id'] ?? null)) {
            $payment = Payment::query()->where('uuid', strtolower($record['crm_id']))->lockForUpdate()->first();
        }

        $payment ??= Payment::query()->where('onec_guid', $guid)->lockForUpdate()->first();

        if ($payment !== null) {
            return $this->update($payment, $guid, $values, $record);
        }

        $exact = $customer === null ? null : Payment::query()
            ->where('source', PaymentSource::Crm)->whereNull('onec_guid')->where('status', PaymentStatus::Recorded)
            ->where('customer_id', $customer->id)->where('amount_minor', $amount)->where('currency', $currency)->whereDate('paid_on', $paidOn)
            ->where('reference_number', $record['reference_number'] ?? null)
            ->lockForUpdate()->first();

        if ($exact !== null) {
            $exact->forceFill(['match_state' => MatchState::Linked])->save();

            return $this->update($exact, $guid, $values, $record);
        }

        $fuzzy = $customer === null ? null : Payment::query()
            ->where('source', PaymentSource::Crm)->whereNull('onec_guid')->whereIn('status', [PaymentStatus::Recorded, PaymentStatus::Confirmed])
            ->where('customer_id', $customer->id)->where('amount_minor', $amount)->where('currency', $currency)
            ->where(fn ($q) => $q->whereBetween('paid_on', [CarbonImmutable::parse($paidOn)->subDays(3)->toDateString(), CarbonImmutable::parse($paidOn)->addDays(3)->toDateString()])
                ->when(filled($record['reference_number'] ?? null), fn ($r) => $r->orWhere('reference_number', $record['reference_number'])))
            ->first();

        $payment = Payment::query()->create([
            ...$values,
            'number' => $this->number($record),
            'direction' => ($record['direction'] ?? 'incoming') === 'outgoing' ? 'outgoing' : 'incoming',
            'kind' => ($record['kind'] ?? 'receipt') === 'refund' ? PaymentKind::Refund : PaymentKind::Receipt,
            'status' => PaymentStatus::Confirmed,
            'source' => PaymentSource::Onec,
            'method' => PaymentMethod::tryFrom((string) ($record['method'] ?? '')) ?? PaymentMethod::BankTransfer,
            'payer_name' => mb_substr((string) ($record['payer_name'] ?? $customer?->name ?? '—'), 0, 255),
            'payer_tax_id' => $record['payer_tax_id'] ?? null,
            'reference_number' => $record['reference_number'] ?? null,
            'purpose' => $record['purpose'] ?? null,
            'period' => AccountingPeriods::of($paidOn),
            'onec_guid' => $guid,
            'onec_acked_at' => now(),
            'match_state' => $fuzzy !== null ? MatchState::PossibleDuplicate : null,
            'duplicate_of_id' => $fuzzy?->id,
        ]);

        $this->ledger->write(EntryType::PaymentConfirmed, $payment->kind === PaymentKind::Refund ? -$amount : $amount, $currency, $paidOn, ['customer_id' => $customer?->id, 'payment_id' => $payment->id], null, 'onec', ['onec_number' => $payment->onec_number]);

        if (($record['is_deleted'] ?? false) === true) {
            return $this->voidDeleted($payment);
        }

        if ($customer !== null && $fuzzy === null && $payment->kind === PaymentKind::Receipt) {
            $this->allocate($payment, $record);
        }

        return ['result' => $customer === null ? 'unmatched' : ($fuzzy !== null ? 'possible_duplicate' : 'created'), 'crm_id' => $payment->uuid];
    }

    /**
     * Link / confirm / correct an existing payment (1C wins on differences).
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $record
     * @return array{result: string, crm_id: string}
     */
    protected function update(Payment $payment, string $guid, array $values, array $record): array
    {
        if ($payment->onec_updated_at !== null && $values['onec_updated_at']->lte($payment->onec_updated_at)) {
            return ['result' => 'unchanged', 'crm_id' => $payment->uuid];
        }

        if (($record['is_deleted'] ?? false) === true) {
            $payment->forceFill(['onec_guid' => $payment->onec_guid ?? $guid, 'onec_updated_at' => $values['onec_updated_at']])->save();

            return $this->voidDeleted($payment);
        }

        $old = ['amount_minor' => $payment->amount_minor, 'currency' => $payment->currency];
        $differs = $payment->amount_minor !== $values['amount_minor'] || $payment->currency !== $values['currency']
            || $payment->paid_on->toDateString() !== $values['paid_on'] || ($values['customer_id'] !== null && $payment->customer_id !== $values['customer_id']);

        $attributes = [...$values, 'onec_guid' => $payment->onec_guid ?? $guid, 'onec_acked_at' => now()];

        if ($values['customer_id'] === null) {
            unset($attributes['customer_id']);
        }

        if ($payment->status->canTransitionTo(PaymentStatus::Confirmed) || $payment->status === PaymentStatus::OnecRejected) {
            $attributes['status'] = PaymentStatus::Confirmed;
        }

        $payment->forceFill($attributes)->save();

        if ($differs) {
            $this->ledger->write(EntryType::OnecCorrection, -$old['amount_minor'], $old['currency'], $payment->paid_on, ['customer_id' => $payment->customer_id, 'payment_id' => $payment->id], null, 'onec', ['reason' => 'reversal_of_crm_values']);
            $this->ledger->write(EntryType::OnecCorrection, $payment->amount_minor, $payment->currency, $payment->paid_on, ['customer_id' => $payment->customer_id, 'payment_id' => $payment->id], null, 'onec', ['reason' => 'values_from_1c']);

            if ($payment->allocated_minor > $payment->amount_minor) {
                $payment->forceFill(['match_state' => MatchState::NeedsReallocation])->save();
            }
        }

        $this->recompute->handle($payment);

        return ['result' => $differs ? 'corrected' : 'updated', 'crm_id' => $payment->uuid];
    }

    /**
     * @return array{result: string, crm_id: string}
     */
    protected function voidDeleted(Payment $payment): array
    {
        if ($payment->status !== PaymentStatus::Voided) {
            PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('reverses_id')->whereDoesntHave('reversal')->get()
                ->each(fn (PaymentAllocation $allocation) => $this->deallocate->handle($allocation, null, 'deleted_in_1c', true));

            $payment->forceFill(['status' => PaymentStatus::Voided, 'voided_at' => now(), 'void_reason' => 'deleted_in_1c'])->save();
            $this->ledger->write(EntryType::PaymentVoided, -$payment->amount_minor, $payment->currency, AccountingPeriods::today(), ['customer_id' => $payment->customer_id, 'payment_id' => $payment->id], null, 'onec', ['reason' => 'deleted_in_1c']);
            $this->recompute->handle($payment);
        }

        return ['result' => 'voided', 'crm_id' => $payment->uuid];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function allocate(Payment $payment, array $record): void
    {
        foreach ((array) ($record['allocations'] ?? []) as $allocation) {
            if (! is_array($allocation) || ! is_string($allocation['order_crm_id'] ?? null) || ! is_string($allocation['amount'] ?? null)) {
                continue;
            }

            $order = Order::query()->where('uuid', strtolower($allocation['order_crm_id']))->first();

            if ($order === null || $order->customer_id !== $payment->customer_id) {
                continue;
            }

            try {
                $this->allocate->handle($payment->fresh(), $order, Money::fromMajor($allocation['amount'], $payment->currency)->amount, null);
            } catch (AccountingActionException) {
                // Does not fit (other currency without a rate, over the order total): stays unallocated.
            } catch (Throwable $exception) {
                // Anything else (e.g. a database error) is a bug: reported, the payment stays unallocated.
                report($exception);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function customer(array $record): ?Customer
    {
        if (is_string($record['customer_id'] ?? null)) {
            $customer = Customer::withTrashed()->where('external_id', strtolower($record['customer_id']))->first();

            if ($customer !== null) {
                return $customer;
            }
        }

        if (is_string($record['customer_crm_id'] ?? null)) {
            return Customer::withTrashed()->where('uuid', strtolower($record['customer_crm_id']))->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function number(array $record): string
    {
        $base = '1C-'.mb_substr(preg_replace('/[^0-9A-Za-zА-Яа-я\-]/u', '', (string) ($record['number'] ?? '')) ?: substr(strtolower($record['id']), 0, 8), 0, 14);

        return Payment::query()->where('number', $base)->exists() ? mb_substr($base, 0, 11).'-'.substr(strtolower($record['id']), 0, 4) : $base;
    }

    /**
     * @return list<array{field: string|null, code: string}>
     */
    protected function validate(mixed $record): array
    {
        if (! is_array($record) || array_is_list($record)) {
            return [['field' => null, 'code' => 'invalid_record']];
        }

        $errors = [];
        $currencies = array_keys((array) config('crm.currencies'));

        if (! is_string($record['id'] ?? null) || preg_match(BatchValidator::GUID_PATTERN, $record['id']) !== 1) {
            $errors[] = ['field' => 'id', 'code' => 'required_uuid'];
        }

        if (! is_string($record['currency'] ?? null) || ! in_array(strtoupper($record['currency']), $currencies, true)) {
            $errors[] = ['field' => 'currency', 'code' => 'invalid_currency'];
        } elseif (! is_string($record['amount'] ?? null) || ! Money::isValidMajor($record['amount'], strtoupper($record['currency'])) || bccomp($record['amount'], '0', 2) <= 0) {
            $errors[] = ['field' => 'amount', 'code' => 'invalid_amount'];
        }

        if (! is_string($record['paid_on'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['paid_on']) !== 1) {
            $errors[] = ['field' => 'paid_on', 'code' => 'invalid_date'];
        }

        if (! $this->validator->isDateTime($record['updated_at'] ?? null)) {
            $errors[] = ['field' => 'updated_at', 'code' => 'invalid_datetime'];
        }

        foreach (['crm_id', 'customer_id', 'customer_crm_id'] as $field) {
            if (isset($record[$field]) && (! is_string($record[$field]) || preg_match(BatchValidator::GUID_PATTERN, $record[$field]) !== 1)) {
                $errors[] = ['field' => $field, 'code' => 'invalid_uuid'];
            }
        }

        return $errors;
    }
}
