<?php

namespace Tests\Feature;

use App\Livewire\Stock\ReconciliationReport;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Reports\ReportRegistry;
use App\Services\Stock\Reconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regressions found by the volume test (roadmap step 8: 100k items, 1M serials, 50k customers):
 * the stock/serials report crashed on enum-cast status keys and lazy-loaded stock per item; the
 * reconciliation page did O(n²) lookups and one warehouse query per row (220 s at volume); foreign
 * keys used for lookups had no index on SQLite/PostgreSQL.
 */
class VolumeRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{0: Item, 1: Warehouse, 2: string, 3: int}>  $rows  item, warehouse, status, count
     */
    private function serials(array $rows): void
    {
        $n = 0;

        foreach ($rows as [$item, $warehouse, $status, $count]) {
            for ($i = 0; $i < $count; $i++, $n++) {
                DB::table('item_serials')->insert([
                    'uuid' => (string) Str::uuid(), 'item_id' => $item->id, 'serial_raw' => 'VS'.$n, 'serial_normalized' => 'VS'.$n, 'status' => $status,
                    'warehouse_id' => $status === 'sold' ? null : $warehouse->id, 'source' => 'opening_balance', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function test_stock_serials_report_counts_serials_with_constant_queries(): void
    {
        $finance = User::factory()->finance()->create();
        $warehouse = Warehouse::factory()->create();
        $items = Item::factory()->serialized()->count(5)->create();
        Item::factory()->create(['name' => 'Plain item']);

        foreach ($items as $item) {
            ItemStock::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => '3', 'reserved' => '1']);
            $this->serials([[$item, $warehouse, 'available', 2], [$item, $warehouse, 'reserved', 1], [$item, $warehouse, 'sold', 4]]);
        }

        $report = app(ReportRegistry::class)->find('stock_serials');
        DB::enableQueryLog();
        $rows = iterator_to_array($report->rows($finance, []), false);
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()), 'No per-item queries.');

        $row = collect($rows)->firstWhere('sku', $items[0]->sku);
        $this->assertSame(['3', '1', 2, 1, 4, ''], [$row['stock'], $row['reserved'], $row['serials_available'], $row['serials_reserved'], $row['serials_sold'], $row['gap']]);
        $this->assertNull(collect($rows)->firstWhere('name', 'Plain item')['serials_available']);

        $this->actingAs($finance)->get(route('reports.show', 'stock_serials'))->assertOk()->assertSee($items[0]->sku);
    }

    public function test_reconciliation_shows_differences_by_default_without_per_row_queries(): void
    {
        $stock = User::factory()->stockManager()->create();
        [$a, $b] = Warehouse::factory()->count(2)->create();
        $matching = Item::factory()->serialized()->create(['name' => 'Matching item']);
        $short = Item::factory()->serialized()->create(['name' => 'Short item']);

        foreach ([[$matching, $a, '2'], [$matching, $b, '1'], [$short, $a, '5']] as [$item, $warehouse, $quantity]) {
            ItemStock::factory()->create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'quantity' => $quantity]);
        }

        $this->serials([[$matching, $a, 'available', 2], [$matching, $b, 'reserved', 1], [$short, $a, 'available', 1]]);

        DB::enableQueryLog();
        $all = app(Reconciliation::class)->rows();
        $this->assertLessThanOrEqual(8, count(DB::getQueryLog()));
        $this->assertCount(3, $all);
        $this->assertSame($b->name, collect($all)->first(fn (array $row): bool => $row['item']->is($matching) && $row['warehouse_id'] === $b->id)['warehouse']);

        $differences = app(Reconciliation::class)->rows(onlyDiscrepancies: true);
        $this->assertSame([[$short->id, '-4.000']], array_map(fn (array $row): array => [$row['item']->id, $row['discrepancy']], $differences));

        Livewire::actingAs($stock)->test(ReconciliationReport::class)
            ->assertSee('Short item')->assertDontSee('Matching item')
            ->set('showAll', true)
            ->assertSee('Matching item');
    }

    public function test_lookup_foreign_keys_are_indexed(): void
    {
        $leading = fn (string $table): array => array_map(fn (array $index): string => $index['columns'][0], Schema::getIndexes($table));

        foreach (['items' => 'category_id', 'item_serials' => 'sold_order_line_id', 'invoice_lines' => 'invoice_id', 'agreements' => 'order_id', 'payment_allocations' => 'invoice_id', 'document_line_serials' => 'item_serial_id', 'lead_follow_ups' => 'lead_id'] as $table => $column) {
            $this->assertContains($column, $leading($table), "{$table}.{$column} needs an index.");
        }
    }
}
