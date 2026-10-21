<?php

namespace App\Services\OneC\Inbound;

use App\Documents\DocumentFormatter;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Warehouse;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Stock balances from 1C (BR-I.4, 9.6): current balance per item x warehouse, decimal(15,3),
 * negative balances accepted, with the stock cost of the row (rev. 4). The batch `as_of` guards ordering: a row is not overwritten by an
 * older calculation.
 */
class StockUpserter
{
    /** @var array<string, int|null> */
    protected array $itemIds = [];

    /** @var array<string, int|null> */
    protected array $warehouseIds = [];

    /** @var array<int, true> */
    protected array $seenIds = [];

    /**
     * @param  array<string, mixed>  $record
     */
    public function upsert(array $record, int $index, CarbonImmutable $asOf, ProcessingResult $result, ?string $snapshotId): void
    {
        $itemGuid = strtolower((string) $record['item_id']);
        $warehouseGuid = strtolower((string) $record['warehouse_id']);
        $label = $itemGuid.'|'.$warehouseGuid;

        $itemId = $this->itemIds[$itemGuid] ??= Item::withTrashed()->where('external_id', $itemGuid)->value('id');
        $warehouseId = $this->warehouseIds[$warehouseGuid] ??= Warehouse::query()->where('external_id', $warehouseGuid)->value('id');

        if ($itemId === null || $warehouseId === null) {
            $result->fail('stock', $index, $label, 'unknown_reference', $itemId === null ? "item {$itemGuid} not found" : "warehouse {$warehouseGuid} not found");

            return;
        }

        $result->warehouseIds[$warehouseGuid] = true;
        $quantity = bcadd((string) $record['quantity'], '0', 3);
        $reserved = bcadd((string) ($record['reserved'] ?? '0'), '0', 3);
        [$costMinor, $costCurrency] = $this->cost($record, $quantity);

        $existing = ItemStock::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->first();

        if ($existing !== null && $existing->as_of->gt($asOf)) {
            $result->count('stale');
            $this->markSeen($existing, $snapshotId);

            return;
        }

        if ($existing !== null && bccomp((string) $existing->quantity, $quantity, 3) === 0 && bccomp((string) $existing->reserved, $reserved, 3) === 0
            && $existing->cost_minor === $costMinor && $existing->cost_currency === $costCurrency) {
            $result->count('unchanged');
            $this->markSeen($existing, $snapshotId);

            if ($existing->as_of->lt($asOf)) {
                ItemStock::query()->whereKey($existing->id)->update(['as_of' => $asOf, 'received_at' => now()]);
            }

            return;
        }

        $attributes = ['quantity' => $quantity, 'reserved' => $reserved, 'cost_minor' => $costMinor, 'cost_currency' => $costCurrency, 'as_of' => $asOf, 'received_at' => now()];

        if ($snapshotId !== null) {
            $attributes['last_seen_snapshot_id'] = $snapshotId;
        }

        try {
            ($existing ?? (new ItemStock)->forceFill(['item_id' => $itemId, 'warehouse_id' => $warehouseId]))->forceFill($attributes)->save();
            $result->count($existing === null ? 'created' : 'updated');
        } catch (QueryException) {
            $result->fail('stock', $index, $label, 'conflict', 'database rejected the stock row');
        }

        if (bccomp($quantity, '0', 3) < 0) {
            $result->warn('stock', $index, $label, 'negative_stock', "negative balance {$quantity} shown as 0 available");
        }
    }

    /**
     * Stock cost of the row (rev. 4): `cost` = total cost of `quantity` (preferred), or `unit_cost`
     * multiplied by the quantity, rounded half-up; currency `cost_currency`, default the CRM base
     * currency. Neither sent = cost unknown (null).
     *
     * @param  array<string, mixed>  $record
     * @return array{0: int|null, 1: string|null}
     */
    protected function cost(array $record, string $quantity): array
    {
        $currency = strtoupper((string) ($record['cost_currency'] ?? config('crm.base_currency')));
        $scale = bcpow('10', (string) Money::exponent($currency));

        if (is_string($record['cost'] ?? null)) {
            return [(int) DocumentFormatter::round(bcmul($record['cost'], $scale, 6), 0), $currency];
        }

        if (is_string($record['unit_cost'] ?? null)) {
            return [(int) DocumentFormatter::round(bcmul(bcmul($record['unit_cost'], $quantity, 9), $scale, 6), 0), $currency];
        }

        return [null, null];
    }

    public function flushSeen(?string $snapshotId): void
    {
        if ($snapshotId !== null) {
            foreach (array_chunk(array_keys($this->seenIds), 500) as $ids) {
                ItemStock::query()->whereKey($ids)->update(['last_seen_snapshot_id' => $snapshotId]);
            }
        }

        $this->seenIds = [];
    }

    protected function markSeen(ItemStock $row, ?string $snapshotId): void
    {
        if ($snapshotId !== null && $row->last_seen_snapshot_id !== $snapshotId) {
            $this->seenIds[$row->id] = true;
        }
    }
}
