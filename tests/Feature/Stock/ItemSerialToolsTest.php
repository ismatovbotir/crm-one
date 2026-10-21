<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\CorrectSerial;
use App\Enums\SerialStatus;
use App\Livewire\Catalog\ItemSerials;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\SerialEvent;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 06 section 21.10: the item page serial card — shown for serialized and non-serialized items,
 * role matrix (stock enters, admin reads with a message), edit with a reason and uniqueness, sold
 * serials not editable, template and export, bulk move.
 */
class ItemSerialToolsTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = User::factory()->stockManager()->create();
        $this->main = Warehouse::factory()->create(['name' => 'Main']);
    }

    public function test_card_is_shown_for_both_kinds_of_items_with_role_specific_tools(): void
    {
        $item = $this->serializedItem($this->main);
        $this->receiveSerials($this->stock, $this->main, $item, ['TL-001', 'TL-002']);
        $plain = Item::factory()->create(['is_serialized' => false]);
        $admin = User::factory()->admin()->create();
        $finance = User::factory()->finance()->create();

        Livewire::actingAs($this->stock)->test(ItemSerials::class, ['itemId' => $item->id])
            ->assertSee('TL-001')->assertSee('data-action="serial-template"', false)->assertSee('data-bulk-move', false)->assertSee(__('stock.serials.history_title'));
        Livewire::actingAs($admin)->test(ItemSerials::class, ['itemId' => $item->id])
            ->assertSee(__('stock.serials.entry_by_stock'))->assertDontSee('data-action="serial-template"', false)->assertDontSee('data-action="edit-serial"', false)->assertSee('TL-001');

        $sales = User::factory()->sales()->create();
        Livewire::actingAs($sales)->test(ItemSerials::class, ['itemId' => $plain->id])->assertSee(__('stock.serials.tracking_off'))->assertSee(__('stock.serials.tracking_who'));
        Livewire::actingAs($sales)->test(ItemSerials::class, ['itemId' => $plain->id])->call('toggleTracking')->assertForbidden();
        Livewire::actingAs($finance)->test(ItemSerials::class, ['itemId' => $plain->id])->assertSee('data-action="enable-tracking"', false)->call('toggleTracking');
        $this->assertTrue($plain->fresh()->is_serialized);
    }

    public function test_edit_needs_a_reason_checks_uniqueness_and_is_logged(): void
    {
        $item = $this->serializedItem($this->main);
        $this->receiveSerials($this->stock, $this->main, $item, ['AB-100', 'AB-200']);
        $serial = ItemSerial::query()->where('serial_normalized', 'AB-100')->sole();

        Livewire::actingAs($this->stock)->test(ItemSerials::class, ['itemId' => $item->id])
            ->call('startEdit', $serial->id)
            ->set('edit.serial', 'ab-200')->set('edit.reason', 'Typo at receipt')->call('saveEdit')
            ->assertHasErrors('edit.serial')
            ->set('edit.serial', 'AB-101')->set('edit.reason', 'x')->call('saveEdit')->assertHasErrors('edit.reason')
            ->set('edit.reason', 'Typo at receipt')->call('saveEdit')->assertHasNoErrors();

        $this->assertSame('AB-101', $serial->fresh()->serial_normalized);
        $this->assertTrue(SerialEvent::query()->where('item_serial_id', $serial->id)->where('event', 'corrected')->exists());
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'item', 'subject_id' => $item->id, 'event' => 'serial_corrected']);

        $serial->forceFill(['status' => SerialStatus::Sold])->save();

        try {
            app(CorrectSerial::class)->handle($serial->fresh(), ['serial' => 'AB-102'], 'Typo at receipt', $this->stock);
            $this->fail('Sold serials are not editable.');
        } catch (StockActionException $exception) {
            $this->assertSame('serial_not_editable', $exception->key);
        }

        $this->expectException(AuthorizationException::class);
        app(CorrectSerial::class)->handle(ItemSerial::query()->where('serial_normalized', 'AB-200')->sole(), ['serial' => 'AB-201'], 'Typo at receipt', User::factory()->admin()->create());
    }

    public function test_template_export_and_bulk_move(): void
    {
        $item = $this->serializedItem($this->main, '10', ['sku' => 'SKU-77']);
        $this->receiveSerials($this->stock, $this->main, $item, ['MV-001', 'MV-002', 'MV-003']);
        $second = Warehouse::factory()->create(['name' => 'Second']);

        $template = $this->actingAs($this->stock)->get(route('items.serials.template', $item))->assertOk()->streamedContent();
        $this->assertStringContainsString('serial_number;warranty_until;note', $template);
        $this->actingAs(User::factory()->sales()->create())->get(route('items.serials.template', $item))->assertForbidden();

        $export = $this->actingAs($this->stock)->get(route('items.serials.export', $item))->assertOk()->streamedContent();
        $this->assertStringContainsString('MV-001', $export);
        $this->actingAs(User::factory()->sales()->create())->get(route('items.serials.export', $item))->assertForbidden();

        $ids = ItemSerial::query()->whereIn('serial_normalized', ['MV-001', 'MV-002'])->pluck('id')->all();
        Livewire::actingAs($this->stock)->test(ItemSerials::class, ['itemId' => $item->id])
            ->set('selected', $ids)->set('moveWarehouseId', $second->id)->set('moveReason', 'Moved to the second hall')->call('moveSelected')->assertHasNoErrors();
        $this->assertSame(2, ItemSerial::query()->where('warehouse_id', $second->id)->count());
        $this->assertSame(2, SerialEvent::query()->where('event', 'transferred')->count());
    }
}
