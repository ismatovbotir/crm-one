<?php

namespace App\Services\Orders;

use App\Enums\OneCFeed;
use App\Enums\ReservationStatus;
use App\Models\IntegrationFeed;
use App\Models\ItemStock;
use App\Models\StockReservation;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Carbon\CarbonImmutable;

/**
 * Available-for-sale quantity (Spec 06 7.5, DEC-8): Σ max(quantity − reserved, 0) of the latest
 * 1C stock snapshot in the counted warehouses, minus the CRM's own `active` reservations
 * (rows mirrored in 1C are already inside `item_stocks.reserved`).
 */
class StockAvailability
{
    /**
     * @param  list<int>  $excludeOrderLineIds  reservations of these lines are not subtracted
     */
    public function forItem(int $itemId, ?int $warehouseId = null, array $excludeOrderLineIds = []): string
    {
        $warehouses = $warehouseId !== null ? [$warehouseId] : array_map('intval', BusinessSettings::array('orders.stock_warehouse_ids'));

        $onHand = ItemStock::query()
            ->where('item_id', $itemId)
            ->when($warehouses !== [], fn ($query) => $query->whereIn('warehouse_id', $warehouses))
            ->get()
            ->reduce(fn (string $sum, ItemStock $stock): string => bcadd($sum, $stock->available(), Quantity::SCALE), '0');

        $reserved = (string) (StockReservation::query()
            ->where('item_id', $itemId)
            ->where('status', ReservationStatus::Active)
            ->when($warehouses !== [], fn ($query) => $query->where(fn ($q) => $q->whereIn('warehouse_id', $warehouses)->orWhereNull('warehouse_id')))
            ->when($excludeOrderLineIds !== [], fn ($query) => $query->whereNotIn('order_line_id', $excludeOrderLineIds))
            ->sum('quantity') ?: '0');

        $available = bcsub($onHand, $reserved, Quantity::SCALE);

        return bccomp($available, '0', Quantity::SCALE) < 0 ? bcadd('0', '0', Quantity::SCALE) : $available;
    }

    /** Minutes since the last stock feed when it is stale (Spec 02 BR-I.21), else null. */
    public function staleMinutes(): ?int
    {
        $status = IntegrationFeed::statuses()[OneCFeed::Stock->value] ?? null;

        if ($status === null || ! $status['is_stale']) {
            return null;
        }

        $last = $status['last_received_at'];

        return $last instanceof CarbonImmutable ? (int) $last->diffInMinutes(now()) : -1;
    }
}
