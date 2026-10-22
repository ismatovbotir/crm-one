<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Catalog\Items\Index;
use App\Livewire\Catalog\Items\Show;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    /** AC-2.1 */
    public function test_min_order_quantity_respects_unit_precision(): void
    {
        $this->actingAs(User::factory()->manager()->create());
        $pcsItem = Item::factory()->withUnit(Unit::factory()->pieces()->create())->create();
        $kgItem = Item::factory()->withUnit(Unit::factory()->kilograms()->create())->create();

        Livewire::test(Show::class, ['item' => $pcsItem])->set('min_order_qty', '1.5')->call('saveLocalFields')->assertHasErrors(['min_order_qty']);
        Livewire::test(Show::class, ['item' => $kgItem])->set('min_order_qty', '1.2345')->call('saveLocalFields')->assertHasErrors(['min_order_qty']);
        Livewire::test(Show::class, ['item' => $kgItem])->set('min_order_qty', '1,5')->set('order_step', '0.5')->call('saveLocalFields')->assertHasNoErrors();

        $kgItem->refresh();
        $this->assertSame('1.500', $kgItem->min_order_qty);
        $this->assertSame('0.500', $kgItem->order_step);
        $this->assertEquals(['min_order_qty' => '1.500', 'order_step' => '0.500'], $kgItem->activities()->first()->properties['new']);
    }

    public function test_order_step_must_be_positive(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $item = Item::factory()->create();

        Livewire::test(Show::class, ['item' => $item])->set('order_step', '0')->call('saveLocalFields')->assertHasErrors(['order_step']);
    }

    /** AC-I.29: no Livewire path can change 1C-owned fields. */
    public function test_sales_cannot_change_local_fields_and_no_action_edits_1c_fields(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        $item = Item::factory()->create(['name' => 'Original']);

        Livewire::test(Show::class, ['item' => $item])->set('min_order_qty', '2')->call('saveLocalFields')->assertForbidden();

        $this->assertNull($item->fresh()->min_order_qty);
        $this->assertFalse(method_exists(Show::class, 'save'));
        $this->assertSame('Original', $item->fresh()->name);
    }

    /** Spec 02 BR-I.13: 1C owns the code, so duplicates are stored; identity is the 1C GUID. */
    public function test_duplicate_skus_are_allowed_but_external_id_is_unique(): void
    {
        $archived = Item::factory()->archived()->create(['sku' => '00-00001234']);
        $twin = Item::factory()->create(['sku' => '00-00001234']);

        $this->assertNotSame($archived->external_id, $twin->external_id);

        $this->expectException(QueryException::class);
        Item::factory()->create(['external_id' => $twin->external_id]);
    }

    public function test_duplicate_sku_is_shown_as_an_informational_warning(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        $first = Item::factory()->create(['sku' => '00-00009999', 'name' => 'Twin A']);
        Item::factory()->create(['sku' => '00-00009999', 'name' => 'Twin B']);
        $single = Item::factory()->create(['name' => 'Single']);

        Livewire::test(Index::class)
            ->assertSet('duplicateSkus', ['00-00009999'])
            ->assertSee(__('catalog.items.duplicate_sku_short'));

        Livewire::test(Show::class, ['item' => $first])
            ->assertSee(trans_choice('catalog.items.duplicate_sku', 1, ['count' => 1]));

        Livewire::test(Show::class, ['item' => $single])
            ->assertDontSee('data-duplicate-sku', false);
    }

    /** AC-2.7 */
    public function test_inactive_items_are_hidden_by_default_but_viewable(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        $inactive = Item::factory()->inactive()->create(['name' => 'Legacy Router']);
        Item::factory()->create(['name' => 'Current Router']);

        Livewire::test(Index::class)
            ->assertSee('Current Router')
            ->assertDontSee('Legacy Router')
            ->set('status', 'inactive')
            ->assertSee('Legacy Router')
            ->assertDontSee('Current Router');

        $this->get(route('items.show', $inactive))->assertOk()->assertSee('Legacy Router');
        $this->assertFalse(Item::query()->sellable()->whereKey($inactive->id)->exists());
    }

    public function test_category_filter_includes_sub_categories(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        Item::factory()->inCategory($child)->create(['name' => 'Child Item']);
        Item::factory()->create(['name' => 'Other Item']);

        Livewire::test(Index::class)
            ->set('category', (string) $root->id)
            ->assertSee('Child Item')
            ->assertDontSee('Other Item');
    }

    public function test_list_shows_the_sale_price_and_available_stock(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        $item = Item::factory()->priced('1250000.50')->create();
        ItemStock::factory()->for($item)->create(['quantity' => '120', 'reserved' => '20']);
        ItemStock::factory()->for($item)->create(['quantity' => '-5', 'reserved' => '0']);

        app()->setLocale('en');

        Livewire::test(Index::class)
            ->assertSee('1,250,000.50')
            ->assertSee('100');

        $this->assertSame('100.000', $item->fresh()->load('stocks')->availableStock(), 'Negative balances count as 0.');
    }

    public function test_item_page_shows_only_the_sale_price_and_stock_quantities(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        app()->setLocale('en');
        $item = Item::factory()->priced('200.00', 'UZS', false, '150.00')->create(['tax_rate_bp' => 1200]);
        ItemStock::factory()->for($item)->create(['quantity' => '10', 'cost_minor' => 120000, 'cost_currency' => 'UZS']);
        ItemStock::factory()->for($item)->create(['quantity' => '30', 'cost_minor' => 420000, 'cost_currency' => 'UZS']);

        // The stored cost basis still feeds offer margins; it is never shown on the item page.
        $this->assertSame(13500, $item->fresh()->stockCostPrice()?->amount);

        Livewire::test(Show::class, ['item' => $item])
            ->set('tab', 'prices')
            ->assertSee('200.00')
            ->assertSee(__('catalog.items.sale_price'))
            ->assertSee(__('catalog.stock.quantity'))
            ->assertDontSee('150.00')
            ->assertDontSee('135.00')
            ->assertDontSee('4,200.00')
            ->assertDontSee(__('catalog.items.purchase_price'))
            ->assertDontSee(__('catalog.items.stock_cost_price'))
            ->assertDontSee(__('catalog.items.margin'))
            ->assertDontSee(__('catalog.stock.cost'))
            ->set('tab', 'activity')
            ->assertOk();
    }

    public function test_cost_basis_falls_back_to_the_purchase_price_and_ignores_other_currencies(): void
    {
        $item = Item::factory()->priced('200.00', 'UZS', false, '150.00')->create();
        $this->assertSame(15000, $item->costBasis('UZS')?->amount);
        $this->assertNull($item->costBasis('USD'));

        ItemStock::factory()->for($item)->create(['quantity' => '2', 'cost_minor' => 1000, 'cost_currency' => 'USD']);
        $this->assertSame(['USD', 500], [$item->fresh()->stockCostPrice()?->currency, $item->fresh()->stockCostPrice()?->amount]);
        $this->assertSame(15000, $item->fresh()->costBasis('UZS')?->amount);
    }
}
