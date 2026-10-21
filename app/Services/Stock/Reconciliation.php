<?php

namespace App\Services\Stock;

use App\Enums\OutboxStatus;
use App\Enums\SerialStatus;
use App\Models\Delivery;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\OnecOutboxEvent;
use App\Models\Warehouse;
use App\Support\Quantity;

/**
 * Serials vs 1C quantities (Spec 06 21.11, BR-SN.11): per serialized item × warehouse, CRM serials
 * on hand (available, reserved, returned, quarantined) against the latest 1C quantity, adjusted by
 * the expected lag of CRM documents 1C has not applied yet (posted delivery notes still counted by
 * 1C, posted receipts not yet in 1C). Discrepancy = CRM − 1C + undelivered-in-1C − unreceived-in-1C.
 * Negative = serials missing (register opening balances); never corrected automatically.
 */
class Reconciliation
{
    /**
     * Lookups are keyed maps (volume test, roadmap step 8: 5k serialized items x warehouses).
     *
     * @return list<array{item: Item, warehouse_id: int, warehouse: string, crm: int, onec: string, lag: int, discrepancy: string}>
     */
    public function rows(bool $onlyDiscrepancies = false): array
    {
        $items = Item::query()->where('is_serialized', true)->get(['id', 'sku', 'name', 'accountant_code'])->keyBy('id');
        $serializedIds = Item::query()->where('is_serialized', true)->select('id');

        if ($items->isEmpty()) {
            return [];
        }

        $onHand = ItemSerial::query()
            ->whereIn('item_id', $serializedIds)
            ->whereIn('status', array_map(fn (SerialStatus $status): string => $status->value, array_filter(SerialStatus::cases(), fn (SerialStatus $status): bool => $status->isOnHand())))
            ->whereNotNull('warehouse_id')
            ->selectRaw('item_id, warehouse_id, count(*) as total')
            ->groupBy('item_id', 'warehouse_id')
            ->get();

        $stocks = ItemStock::query()->whereIn('item_id', $serializedIds)->get(['item_id', 'warehouse_id', 'quantity'])
            ->keyBy(fn (ItemStock $stock): string => $stock->item_id.'|'.$stock->warehouse_id);
        $warehouseNames = Warehouse::query()->pluck('name', 'id');
        $pendingDeliveries = $this->pending('delivery', Delivery::DOCUMENT_TYPE);
        $pendingReceipts = $this->pending('goods_receipt', GoodsReceipt::DOCUMENT_TYPE);
        $crmCounts = [];

        foreach ($onHand as $row) {
            $crmCounts[$row->item_id.'|'.$row->warehouse_id] = (int) $row->total;
        }

        $keys = array_unique([...array_keys($crmCounts), ...$stocks->keys()->all()]);
        $rows = [];

        foreach ($keys as $key) {
            [$itemId, $warehouseId] = array_map('intval', explode('|', (string) $key));
            $crm = $crmCounts[$key] ?? 0;
            $stock = $stocks->get($key);
            $onec = $stock !== null ? (string) $stock->quantity : '0';
            $lag = ($pendingDeliveries[$key] ?? 0) - ($pendingReceipts[$key] ?? 0);
            $discrepancy = bcsub(bcadd((string) $crm, (string) $lag, Quantity::SCALE), $onec, Quantity::SCALE);

            if ($onlyDiscrepancies && bccomp($discrepancy, '0', Quantity::SCALE) === 0) {
                continue;
            }

            $rows[] = [
                'item' => $items[$itemId],
                'warehouse_id' => $warehouseId,
                'warehouse' => (string) ($warehouseNames[$warehouseId] ?? ''),
                'crm' => $crm,
                'onec' => $onec,
                'lag' => $lag,
                'discrepancy' => $discrepancy,
            ];
        }

        usort($rows, fn (array $a, array $b): int => bccomp($a['discrepancy'], $b['discrepancy'], 3));

        return $rows;
    }

    /**
     * Serial counts per "item|warehouse" of posted documents whose outbox event 1C has not acked.
     *
     * @return array<string, int>
     */
    protected function pending(string $aggregateType, string $documentType): array
    {
        $ids = OnecOutboxEvent::query()->where('aggregate_type', $aggregateType)
            ->whereIn('status', [OutboxStatus::Pending, OutboxStatus::Delivered, OutboxStatus::BlockedCustomer])->pluck('aggregate_id');

        if ($ids->isEmpty()) {
            return [];
        }

        $warehouses = $aggregateType === 'delivery'
            ? Delivery::query()->whereIn('id', $ids)->pluck('warehouse_id', 'id')
            : GoodsReceipt::query()->whereIn('id', $ids)->pluck('warehouse_id', 'id');
        $counts = [];

        foreach (DocumentLineSerial::query()->where('document_type', $documentType)->whereIn('document_id', $ids)->where('status', DocumentLineSerial::POSTED)->get(['document_id', 'item_id']) as $row) {
            $key = $row->item_id.'|'.$warehouses[$row->document_id];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
