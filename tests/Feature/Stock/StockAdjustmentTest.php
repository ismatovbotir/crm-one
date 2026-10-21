<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\ClaimSerials;
use App\Actions\Stock\ManageDeliveryNote;
use App\Actions\StockAdjustment\ManageStockAdjustment;
use App\Actions\StockAdjustment\TransitionStockAdjustment;
use App\Enums\DepartmentRole;
use App\Enums\SerialStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockAdjustmentType;
use App\Livewire\Stock\StockAdjustments;
use App\Livewire\Stock\StockAdjustmentShow;
use App\Models\DocumentLineSerial;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\OnecOutboxEvent;
use App\Models\SerialEvent;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockAdjustmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 06 section 21.4: stock adjustments (found serials, serial correction on a posted delivery
 * note, quantity correction) with approval by someone other than the requester, posting effects
 * and the `stock_adjustment.posted` event available for 1C to pull.
 */
class StockAdjustmentTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private User $head;

    private User $manager;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
        $this->stock = User::factory()->stockManager()->create();
        $this->head = User::factory()->staff()->inDepartment('warehouse', DepartmentRole::Head)->create();
        $this->warehouse = Warehouse::factory()->create(['name' => 'Main']);
    }

    private function draft(StockAdjustmentType $type, array $overrides = []): StockAdjustment
    {
        return app(ManageStockAdjustment::class)->create(['type' => $type->value, 'warehouse_id' => $this->warehouse->id, 'reason' => 'Counted on the shelf', ...$overrides], $this->stock);
    }

    private function approve(StockAdjustment $adjustment): StockAdjustment
    {
        $moves = app(TransitionStockAdjustment::class);
        $moves->handle($adjustment, StockAdjustmentStatus::PendingApproval, $this->stock);

        return $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Posted, $this->manager, 'Checked');
    }

    public function test_found_serial_is_registered_after_someone_else_approves(): void
    {
        $item = $this->serializedItem($this->warehouse, '3');
        $adjustment = $this->draft(StockAdjustmentType::Found);
        $this->assertMatchesRegularExpression('/^ADJ-\d{4}-\d{5}$/', $adjustment->number);
        app(ManageStockAdjustment::class)->addFoundSerial($adjustment, $item, ' fnd-0001 ');

        $moves = app(TransitionStockAdjustment::class);
        $moves->handle($adjustment, StockAdjustmentStatus::PendingApproval, $this->head);
        $this->expectError('own_adjustment', fn () => $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Posted, $this->head));
        $this->expectError('forbidden', fn () => $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Posted, $this->stock));
        $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Posted, $this->manager, 'Checked on the shelf');

        $serial = ItemSerial::query()->where('serial_normalized', 'FND-0001')->sole();
        $this->assertSame(SerialStatus::Available, $serial->status);
        $this->assertSame($this->warehouse->id, $serial->warehouse_id);
        $this->assertSame('found', $serial->source->value);
        $this->assertTrue(SerialEvent::query()->where('item_serial_id', $serial->id)->where('event', 'found')->where('document_type', 'stock_adjustment')->exists());
        $this->assertSame('4.000', (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame(StockAdjustmentStatus::Posted, $adjustment->fresh()->status);
        $this->assertNotNull($adjustment->fresh()->posted_at);

        $event = OnecOutboxEvent::query()->where('event_type', 'stock_adjustment.posted')->sole();
        $this->assertSame('warehouse', $event->feed);
        $this->assertSame('found', $event->payload['type']);
        $this->assertSame('FND-0001', $event->payload['lines'][0]['serial']);

        $this->assertDatabaseHas('status_transitions', ['subject_type' => 'stock_adjustment', 'subject_id' => $adjustment->id, 'to_status' => 'posted']);
    }

    public function test_registered_serials_are_refused_but_a_written_off_one_comes_back(): void
    {
        $item = $this->serializedItem($this->warehouse, '0');
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['KEEP-1', 'GONE-1']);
        ItemSerial::query()->where('serial_normalized', 'GONE-1')->update(['status' => SerialStatus::WrittenOff, 'warehouse_id' => null]);
        $manage = app(ManageStockAdjustment::class);
        $adjustment = $this->draft(StockAdjustmentType::Found);

        $this->expectError('serial_registered', fn () => $manage->addFoundSerial($adjustment, $item, 'keep-1'));
        $this->expectError('serial_invalid', fn () => $manage->addFoundSerial($adjustment, $item, 'СН0003'));
        $manage->addFoundSerial($adjustment, $item, 'gone-1');
        $this->expectError('serial_on_open_adjustment', fn () => $manage->addFoundSerial($this->draft(StockAdjustmentType::Found), $item, 'GONE-1'));

        $plain = Item::factory()->create(['is_serialized' => false]);
        $this->expectError('item_not_serialized', fn () => $manage->addFoundSerial($adjustment, $plain, 'ANY-123'));

        $this->approve($adjustment);

        $serial = ItemSerial::query()->where('serial_normalized', 'GONE-1')->sole();
        $this->assertSame(SerialStatus::Available, $serial->status);
        $this->assertSame($this->warehouse->id, $serial->warehouse_id);
        $this->assertSame(1, ItemSerial::query()->where('serial_normalized', 'GONE-1')->count());
    }

    public function test_serial_correction_swaps_the_serial_on_a_posted_delivery_note(): void
    {
        $item = $this->serializedItem($this->warehouse, '10', ['warranty_months' => 12]);
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['OLD-1', 'NEW-1', 'NEW-2']);
        $rep = User::factory()->sales()->create();
        $order = $this->orderInFulfilment($rep, $item, '1');
        $delivery = app(ManageDeliveryNote::class)->create($order, $rep, $this->warehouse->id);
        app(ClaimSerials::class)->handle($delivery->lines()->sole(), ['OLD-1'], $this->stock);
        $delivery = $this->packAndPost($delivery, $this->stock);
        $oldSold = ItemSerial::query()->where('serial_normalized', 'OLD-1')->sole();
        $this->assertSame(SerialStatus::Sold, $oldSold->status);

        $manage = app(ManageStockAdjustment::class);
        $adjustment = $manage->create(['type' => 'serial_correction', 'delivery_id' => $delivery->id, 'reason' => 'Scanned the wrong unit'], $this->stock);
        $this->assertSame($this->warehouse->id, $adjustment->warehouse_id);
        $row = DocumentLineSerial::query()->where('document_type', 'delivery_note')->where('document_id', $delivery->id)->where('status', 'posted')->sole();

        $this->expectError('replacement_not_found', fn () => $manage->addCorrection($adjustment, $row, 'NOPE-9', 'available'));
        $this->expectError('outcome_required', fn () => $manage->addCorrection($adjustment, $row, 'NEW-1', 'sold'));
        $manage->addCorrection($adjustment, $row, 'new-1', 'quarantined');
        $this->expectError('serial_already_added', fn () => $manage->addCorrection($adjustment, $row, 'NEW-2', 'available'));

        $this->approve($adjustment);

        $old = $oldSold->fresh();
        $new = ItemSerial::query()->where('serial_normalized', 'NEW-1')->sole();
        $this->assertSame(SerialStatus::Quarantined, $old->status);
        $this->assertNull($old->customer_id);
        $this->assertSame(SerialStatus::Sold, $new->status);
        $this->assertSame($order->customer_id, $new->customer_id);
        $this->assertNotNull($new->warranty_until);
        $this->assertSame($oldSold->sold_delivery_line_id, $new->sold_delivery_line_id);
        $this->assertSame('replaced', $row->fresh()->status);
        $replacement = DocumentLineSerial::query()->whereKey($row->fresh()->replaced_by_id)->sole();
        $this->assertSame(['NEW-1', 'posted'], [$replacement->serial_number, $replacement->status]);
        $this->assertTrue(SerialEvent::query()->where('item_serial_id', $new->id)->where('event', 'corrected')->exists());

        $event = OnecOutboxEvent::query()->where('event_type', 'stock_adjustment.posted')->sole();
        $this->assertSame(['serial_correction', 'OLD-1', 'NEW-1'], [$event->payload['type'], $event->payload['lines'][0]['old_serial'], $event->payload['lines'][0]['serial']]);
    }

    public function test_quantity_correction_moves_the_mirror_and_never_below_zero(): void
    {
        $item = Item::factory()->create(['is_serialized' => false]);
        ItemStock::factory()->create(['item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => '5', 'reserved' => '0']);
        $manage = app(ManageStockAdjustment::class);

        $adjustment = $this->draft(StockAdjustmentType::QtyCorrection);
        $this->expectError('quantity_invalid', fn () => $manage->addQuantity($adjustment, $item, '0'));
        $this->expectError('quantity_invalid', fn () => $manage->addQuantity($adjustment, $item, 'abc'));
        $manage->addQuantity($adjustment, $item, '-2,5', 'Broken box');
        $this->expectError('item_already_added', fn () => $manage->addQuantity($adjustment, $item, '1'));
        $this->approve($adjustment);
        $this->assertSame('2.500', (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));

        $tooMuch = $this->draft(StockAdjustmentType::QtyCorrection);
        $manage->addQuantity($tooMuch, $item, '-10');
        $moves = app(TransitionStockAdjustment::class);
        $moves->handle($tooMuch, StockAdjustmentStatus::PendingApproval, $this->stock);
        $this->expectError('stock_below_zero', fn () => $moves->handle($tooMuch->fresh(), StockAdjustmentStatus::Posted, $this->manager));
        $this->assertSame(StockAdjustmentStatus::PendingApproval, $tooMuch->fresh()->status);
        $this->assertSame('2.500', (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));

        $plus = $this->draft(StockAdjustmentType::QtyCorrection);
        $manage->addQuantity($plus, $item, '4');
        $this->approve($plus);
        $this->assertSame('6.500', (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));

        $serialized = $this->serializedItem($this->warehouse, '1');
        $this->expectError('item_serialized', fn () => $manage->addQuantity($this->draft(StockAdjustmentType::QtyCorrection), $serialized, '1'));
    }

    public function test_workflow_rules_rejecting_reworking_and_cancelling(): void
    {
        $item = $this->serializedItem($this->warehouse, '1');
        $manage = app(ManageStockAdjustment::class);
        $moves = app(TransitionStockAdjustment::class);
        $adjustment = $this->draft(StockAdjustmentType::Found);

        $this->expectError('lines_incomplete', fn () => $moves->handle($adjustment, StockAdjustmentStatus::PendingApproval, $this->stock));
        $manage->addFoundSerial($adjustment, $item, 'FND-7');
        $moves->handle($adjustment->fresh(), StockAdjustmentStatus::PendingApproval, $this->stock);
        $this->expectError('not_editable', fn () => $manage->updateReason($adjustment->fresh(), 'Changed'));
        $this->expectError('reason_required', fn () => $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Rejected, $this->manager));
        $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Rejected, $this->manager, 'Not on the shelf');
        $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Draft, $this->stock);
        $this->expectError('invalid_transition', fn () => $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Posted, $this->manager));
        $this->expectError('reason_required', fn () => $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Cancelled, $this->stock));
        $moves->handle($adjustment->fresh(), StockAdjustmentStatus::Cancelled, $this->stock, 'Duplicate');

        $this->assertSame(StockAdjustmentStatus::Cancelled, $adjustment->fresh()->status);
        $this->assertSame(0, ItemSerial::query()->where('serial_normalized', 'FND-7')->count());
        $this->assertSame(0, OnecOutboxEvent::query()->where('event_type', 'stock_adjustment.posted')->count());
        $manage->addFoundSerial($this->draft(StockAdjustmentType::Found), $item, 'FND-7');
    }

    public function test_screens_follow_the_permissions(): void
    {
        $item = $this->serializedItem($this->warehouse, '1');
        $rep = User::factory()->sales()->create();
        $this->actingAs($rep)->get(route('stock-adjustments.index'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('stock-adjustments.index'))->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->get(route('stock-adjustments.index'))->assertOk();

        Livewire::actingAs($this->stock)->test(StockAdjustments::class)
            ->set('creating', true)
            ->set('form.type', 'found')
            ->set('form.warehouse_id', (string) $this->warehouse->id)
            ->call('create')->assertHasErrors(['form.reason'])
            ->set('form.reason', 'Found behind the rack')
            ->call('create')->assertHasNoErrors();

        $adjustment = StockAdjustment::query()->sole();
        Livewire::actingAs($rep)->test(StockAdjustments::class)->assertForbidden();

        Livewire::actingAs($this->stock)->test(StockAdjustmentShow::class, ['adjustment' => $adjustment])
            ->assertSee($adjustment->number)
            ->set('itemSearch', $item->sku)
            ->call('pickItem', $item->id)
            ->set('serialInput', 'UI-0001')
            ->call('addFound')
            ->assertSee('UI-0001')
            ->call('move', 'pending_approval')
            ->assertSeeHtml('data-own-adjustment')
            ->assertDontSeeHtml('data-action="posted"');

        Livewire::actingAs($this->manager)->test(StockAdjustmentShow::class, ['adjustment' => $adjustment])
            ->assertSeeHtml('data-action="posted"')
            ->call('move', 'posted')
            ->assertSee(__('stock_adjustments.status.posted'));

        $this->assertSame(SerialStatus::Available, ItemSerial::query()->where('serial_normalized', 'UI-0001')->sole()->status);
        Livewire::actingAs($rep)->test(StockAdjustmentShow::class, ['adjustment' => $adjustment])->assertForbidden();
    }

    private function expectError(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected stock adjustment error '.$key);
        } catch (StockAdjustmentException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }
}
