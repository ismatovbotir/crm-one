<?php

namespace Tests\Feature\Stock;

use App\Livewire\Stock\GoodsReceipts;
use App\Models\ItemSerial;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Warehouse screens, navigation and template download (Spec 06 21.10).
 */
class StockScreensTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    public function test_stock_manager_screens_render_and_create_a_receipt(): void
    {
        $stock = User::factory()->stockManager()->create();
        $warehouse = Warehouse::factory()->create();
        $item = $this->serializedItem($warehouse);
        $this->receiveSerials($stock, $warehouse, $item, ['SCR0001']);
        $serial = ItemSerial::query()->sole();

        foreach (['goods-receipts.index', 'delivery-notes.index', 'serials.index', 'stock.reconciliation'] as $route) {
            $this->actingAs($stock)->get(route($route))->assertOk();
        }

        $this->actingAs($stock)->get(route('serials.show', $serial))->assertOk()->assertSee('SCR0001')->assertSee(__('stock.events.registered'));
        $this->actingAs($stock)->get(route('serials.index', ['search' => 'scr']))->assertOk()->assertSee('SCR0001');
        $this->actingAs($stock)->get(route('stock.serial-template'))->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->actingAs($stock)->get(route('dashboard'))->assertSee(__('nav.goods_receipts'))->assertSee(__('nav.serials'))->assertDontSee(__('nav.payments'));

        Livewire::actingAs($stock)->test(GoodsReceipts::class)
            ->set('creating', true)
            ->set('form.source_type', 'opening_balance')
            ->set('form.warehouse_id', (string) $warehouse->id)
            ->call('create')
            ->assertRedirect();
    }

    public function test_sales_see_only_serials_sold_on_their_orders(): void
    {
        $rep = User::factory()->sales()->create();
        $stock = User::factory()->stockManager()->create();
        $warehouse = Warehouse::factory()->create();
        $item = $this->serializedItem($warehouse);
        $this->receiveSerials($stock, $warehouse, $item, ['VIS0001']);
        $serial = ItemSerial::query()->sole();

        $this->actingAs($rep)->get(route('serials.show', $serial))->assertForbidden();
        $this->actingAs($rep)->get(route('goods-receipts.index'))->assertForbidden();
        // Admins open the Catalogue serial list read-only (Spec 06 SoD-S4: no add/import).
        $this->actingAs(User::factory()->admin()->create())->get(route('serials.index'))->assertOk();
    }
}
