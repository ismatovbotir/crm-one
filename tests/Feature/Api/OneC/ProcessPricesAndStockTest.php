<?php

namespace Tests\Feature\Api\OneC;

use App\Models\ActivityLog;
use App\Models\InboundBatch;
use App\Models\Item;
use App\Models\ItemStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 rev. 4: one sale and one purchase price per item inside the items feed (minor units,
 * rounding, absent = unchanged, null = cleared) and stock rows with their cost (weighted average
 * stock cost price); AC-I.26 stock ordering and full snapshots.
 */
class ProcessPricesAndStockTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
        $this->seedItemsThroughApi(['00-1', '00-2']);
    }

    /** No price history (2026-10-19): only the current prices are kept; price changes are not logged. */
    public function test_item_prices_are_stored_in_minor_units_in_place_without_history(): void
    {
        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', [
            'sale_price' => '125000.00', 'purchase_price' => '90000.00', 'prices_include_vat' => true, 'updated_at' => '2026-10-17T09:00:00+05:00',
        ])]]))->assertStatus(202);

        $item = Item::query()->where('sku', '00-1')->sole();
        $this->assertSame([12500000, 'UZS', 9000000, 'UZS', true], [$item->sale_price_minor, $item->sale_currency, $item->purchase_price_minor, $item->purchase_currency, $item->prices_include_vat]);

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', [
            'sale_price' => '130000.00', 'sale_currency' => 'UZS', 'updated_at' => '2026-10-17T10:00:00+05:00',
        ])]]))->assertStatus(202);

        $item->refresh();
        $this->assertSame(13000000, $item->sale_price_minor);
        $this->assertSame(9000000, $item->purchase_price_minor, 'Absent purchase_price leaves it unchanged.');
        $this->assertFalse(ActivityLog::query()->where('subject_type', 'item')->where('subject_id', $item->id)->where('properties', 'like', '%price_minor%')->exists(), 'No price history in the change log.');
        $this->assertSame(1, Item::query()->where('sku', '00-1')->count(), 'Upserted in place.');
    }

    public function test_extra_decimals_are_rounded_half_up_with_a_warning(): void
    {
        $body = $this->envelope(['items' => [$this->itemRecord('00-1', ['sale_price' => '100.505', 'updated_at' => '2026-10-17T09:00:00+05:00'])]]);
        $this->onec('POST', '/items', $body)->assertStatus(202);

        $this->assertSame(10051, Item::query()->where('sku', '00-1')->value('sale_price_minor'));
        $this->assertSame('price_rounded', collect(InboundBatch::query()->where('batch_id', $body['batch_id'])->sole()->errors)->value('code'));
    }

    public function test_null_clears_a_price_and_other_currencies_are_kept(): void
    {
        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', ['sale_price' => '10.00', 'sale_currency' => 'USD', 'purchase_price' => '7.00', 'purchase_currency' => 'USD', 'updated_at' => '2026-10-17T09:00:00+05:00'])]]))->assertStatus(202);
        $this->assertSame(['USD', 1000], [Item::query()->where('sku', '00-1')->value('sale_currency'), Item::query()->where('sku', '00-1')->value('sale_price_minor')]);

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', ['sale_price' => null, 'updated_at' => '2026-10-17T10:00:00+05:00'])]]))->assertStatus(202);

        $item = Item::query()->where('sku', '00-1')->sole();
        $this->assertNull($item->salePrice());
        $this->assertSame(700, $item->purchase_price_minor);
    }

    public function test_stock_cost_is_stored_and_the_stock_cost_price_is_a_weighted_average(): void
    {
        $this->onec('POST', '/item-stock', $this->envelope([
            'as_of' => '2026-10-16T14:10:00+05:00',
            'warehouses' => [$this->warehouseRecord('tas'), $this->warehouseRecord('skd')],
            'stock' => [
                [...$this->stockRecord('00-1', '10.000', 'tas'), 'cost' => '1000.00'],
                [...$this->stockRecord('00-1', '30.000', 'skd'), 'unit_cost' => '120.00'],
                [...$this->stockRecord('00-2', '5.000', 'tas')],
            ],
        ]))->assertStatus(202);

        $item = Item::query()->where('sku', '00-1')->sole()->load('stocks');
        $this->assertEqualsCanonicalizing([100000, 360000], $item->stocks->pluck('cost_minor')->all());
        // (1 000.00 + 3 600.00) / 40 = 115.00
        $this->assertSame(11500, $item->stockCostPrice()?->amount);
        $this->assertNull(Item::query()->where('sku', '00-2')->sole()->load('stocks')->stockCostPrice(), 'No cost reported = unknown.');
        $this->assertSame(11500, $item->costBasis('UZS')?->amount);
    }

    public function test_stock_cost_must_be_a_decimal_string(): void
    {
        $this->onec('POST', '/item-stock', $this->envelope([
            'as_of' => '2026-10-16T14:10:00+05:00',
            'warehouses' => [$this->warehouseRecord()],
            'stock' => [[...$this->stockRecord('00-1', '1.000'), 'cost' => 10.5], [...$this->stockRecord('00-2', '1.000'), 'cost' => '10.50']],
        ]))->assertStatus(207)->assertJsonPath('rejected.0.errors.0.code', 'must_be_decimal_string');
    }

    public function test_stock_is_stored_and_older_as_of_is_ignored(): void
    {
        $this->onec('POST', '/item-stock', $this->envelope([
            'as_of' => '2026-10-16T14:10:00+05:00',
            'warehouses' => [$this->warehouseRecord()],
            'stock' => [$this->stockRecord('00-1', '120.000', reserved: '20.000'), $this->stockRecord('00-2', '-3.500')],
        ]))->assertStatus(202);

        $row = ItemStock::query()->whereHas('item', fn ($q) => $q->where('sku', '00-1'))->sole();
        $this->assertSame('120.000', (string) $row->quantity);
        $this->assertSame('100.000', $row->available());

        $negative = ItemStock::query()->whereHas('item', fn ($q) => $q->where('sku', '00-2'))->sole();
        $this->assertTrue($negative->isNegative());
        $this->assertSame('0.000', $negative->available());

        $this->onec('POST', '/item-stock', $this->envelope([
            'as_of' => '2026-10-16T14:00:00+05:00',
            'stock' => [$this->stockRecord('00-1', '1.000')],
        ]))->assertStatus(202);

        $this->assertSame('120.000', (string) $row->fresh()->quantity);
        $this->assertSame(1, InboundBatch::query()->latest('id')->first()->counts['stale']);
    }

    public function test_full_stock_snapshot_removes_rows_missing_from_it(): void
    {
        $this->onec('POST', '/item-stock', $this->envelope([
            'as_of' => '2026-10-16T14:10:00+05:00',
            'warehouses' => [$this->warehouseRecord()],
            'stock' => [$this->stockRecord('00-1', '5'), $this->stockRecord('00-2', '7')],
        ]))->assertStatus(202);

        config(['onec.inbound.full_snapshot_guard_percent' => 50]);

        $this->onec('POST', '/item-stock', $this->envelope([
            'as_of' => '2026-10-16T14:20:00+05:00',
            'stock' => [$this->stockRecord('00-1', '6')],
        ], 'full', ['id' => $this->guid('snap-stock-1'), 'part' => 1, 'total_parts' => 1, 'scope' => ['warehouse_ids' => [$this->guid('wh-tas')]]]))->assertStatus(202);

        $this->assertSame(['00-1'], ItemStock::query()->with('item')->get()->pluck('item.sku')->all());
    }
}
