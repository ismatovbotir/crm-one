<?php

namespace App\Services\Reports\Definitions;

use App\Authorization\PermissionMap;
use App\Enums\SerialStatus;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\User;
use App\Services\Reports\Report;
use App\Support\Quantity;

/**
 * Stock and serial numbers per item (point in time): stock, reserved, and for serialized items the
 * serials available, reserved and sold; flags a gap when serials on hand differ from the stock.
 */
class StockSerials extends Report
{
    public function key(): string
    {
        return 'stock_serials';
    }

    public function allowed(User $user): bool
    {
        return PermissionMap::allows($user, 'stock.view');
    }

    public function usesDateRange(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            ['key' => 'sku', 'type' => 'text'], ['key' => 'name', 'type' => 'text'], ['key' => 'stock', 'type' => 'text'], ['key' => 'reserved', 'type' => 'text'],
            ['key' => 'serials_available', 'type' => 'int'], ['key' => 'serials_reserved', 'type' => 'int'], ['key' => 'serials_sold', 'type' => 'int'], ['key' => 'gap', 'type' => 'text'],
        ];
    }

    /**
     * Two grouped aggregates (serial counts, stock sums) and one streamed pass over items without
     * models (volume test, roadmap step 8: 100k items, 1M serials). Quantities use BCMath.
     */
    public function rows(User $user, array $filters): iterable
    {
        $counts = [];

        foreach (ItemSerial::query()->toBase()->selectRaw('item_id, status, count(*) as c')->groupBy('item_id', 'status')->cursor() as $row) {
            $counts[(int) $row->item_id][(string) $row->status] = (int) $row->c;
        }

        $stocks = [];

        foreach (ItemStock::query()->toBase()->selectRaw('item_id, sum(quantity) as quantity, sum(reserved) as reserved')->groupBy('item_id')->cursor() as $row) {
            $stocks[(int) $row->item_id] = [Quantity::toStorage((string) $row->quantity), Quantity::toStorage((string) $row->reserved)];
        }

        $items = Item::query()->toBase()->whereNull('deleted_at')->select(['id', 'sku', 'name', 'is_serialized'])->orderBy('name')->orderBy('id')->cursor();

        foreach ($items as $item) {
            [$stock, $reserved] = $stocks[(int) $item->id] ?? ['0', '0'];
            $serials = $counts[(int) $item->id] ?? [];
            $available = $serials[SerialStatus::Available->value] ?? 0;
            $held = $serials[SerialStatus::Reserved->value] ?? 0;
            $isSerialized = (bool) $item->is_serialized;

            yield [
                'sku' => (string) $item->sku,
                'name' => (string) $item->name,
                'stock' => Quantity::trimmed($stock),
                'reserved' => Quantity::trimmed($reserved),
                'serials_available' => $isSerialized ? $available : null,
                'serials_reserved' => $isSerialized ? $held : null,
                'serials_sold' => $isSerialized ? ($serials[SerialStatus::Sold->value] ?? 0) : null,
                'gap' => $isSerialized && Quantity::compare((string) ($available + $held), $stock) !== 0 ? __('reports.values.serial_gap', ['serials' => $available + $held]) : '',
            ];
        }
    }
}
