<?php

namespace App\Services\Accounting;

use App\Enums\EntryType;
use App\Models\AccountingEntry;
use App\Models\User;
use DateTimeInterface;

/**
 * Writes the append-only ledger (Spec 06 DEC-4, BR-AC.15). Call inside the transaction that
 * changes the cached balances. Meta never contains bank account numbers.
 */
class Ledger
{
    /**
     * @param  array{customer_id?: int|null, order_id?: int|null, invoice_id?: int|null, payment_id?: int|null, allocation_id?: int|null, reverses_entry_id?: int|null}  $refs
     * @param  array<string, mixed>|null  $meta
     */
    public function write(EntryType $type, int $amountMinor, string $currency, DateTimeInterface|string $occurredOn, array $refs, ?User $causer, string $source = 'crm', ?array $meta = null): AccountingEntry
    {
        $date = $occurredOn instanceof DateTimeInterface ? $occurredOn->format('Y-m-d') : $occurredOn;

        return AccountingEntry::query()->create([
            'entry_type' => $type,
            'customer_id' => $refs['customer_id'] ?? null,
            'order_id' => $refs['order_id'] ?? null,
            'invoice_id' => $refs['invoice_id'] ?? null,
            'payment_id' => $refs['payment_id'] ?? null,
            'allocation_id' => $refs['allocation_id'] ?? null,
            'reverses_entry_id' => $refs['reverses_entry_id'] ?? null,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'occurred_on' => $date,
            'period' => AccountingPeriods::of($date),
            'source' => $source,
            'causer_id' => $causer?->id,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
