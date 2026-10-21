<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\PostGoodsReceipt;
use App\Actions\Stock\TransitionGoodsReceipt;
use App\Actions\StockOff\ManageStockOffAct;
use App\Actions\StockOff\TransitionStockOffAct;
use App\Enums\DepartmentRole;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SerialStatus;
use App\Enums\StockOffReason;
use App\Enums\StockOffStatus;
use App\Livewire\Stock\StockOffActs;
use App\Livewire\Stock\StockOffActShow;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\OnecOutboxEvent;
use App\Models\SerialEvent;
use App\Models\Setting;
use App\Models\StockOffAct;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockOffException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 06 section 27: stock-off acts (write-offs and damage) with approval, SoD, posting effects
 * and the `stock_off.posted` event available for 1C to pull.
 */
class StockOffActTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private User $head;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->manager()->create();
        $this->stock = User::factory()->stockManager()->create();
        $this->head = User::factory()->staff()->inDepartment('warehouse', DepartmentRole::Head)->create();
        $this->warehouse = Warehouse::factory()->create(['name' => 'Main']);
    }

    public function test_receipt_damage_opens_a_draft_act_that_is_approved_by_another_person_and_posted(): void
    {
        $item = $this->serializedItem($this->warehouse, '10');
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '2']]);
        $transition = app(TransitionGoodsReceipt::class);
        $transition->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock);
        $line = $receipt->lines()->sole();
        app(AddReceiptSerials::class)->handle($line, ['DMG-001', 'DMG-002'], $this->stock, null, false, ['DMG-002' => 'Cracked case']);
        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock);
        app(PostGoodsReceipt::class)->handle($receipt->fresh(), $this->stock);

        $act = StockOffAct::query()->sole();
        $this->assertSame(StockOffStatus::Draft, $act->status);
        $this->assertSame(StockOffReason::DamagedAtReceipt, $act->reason);
        $this->assertSame($receipt->id, $act->goods_receipt_id);
        $this->assertSame($receipt->supplier_partner_id, $act->supplier_partner_id);
        $this->assertSame(['DMG-002'], $act->lines()->sole()->serials()->pluck('serial_number')->all());
        $this->assertSame('1.000', (string) $act->lines()->sole()->quantity);

        $moves = app(TransitionStockOffAct::class);
        $moves->handle($act, StockOffStatus::Submitted, $this->head);
        $this->expectOffError('own_act', fn () => $moves->handle($act->fresh(), StockOffStatus::Approved, $this->head));
        $this->expectOffError('forbidden', fn () => $moves->handle($act->fresh(), StockOffStatus::Approved, $this->stock));
        $moves->handle($act->fresh(), StockOffStatus::Approved, User::factory()->manager()->create(), 'Photos checked');
        $stockBefore = (string) ItemStock::query()->where('item_id', $item->id)->value('quantity');
        $moves->handle($act->fresh(), StockOffStatus::Posted, $this->stock);

        $serial = ItemSerial::query()->where('serial_normalized', 'DMG-002')->sole();
        $this->assertSame(SerialStatus::WrittenOff, $serial->status);
        $this->assertSame(SerialStatus::Available, ItemSerial::query()->where('serial_normalized', 'DMG-001')->sole()->status);
        $this->assertTrue(SerialEvent::query()->where('item_serial_id', $serial->id)->where('event', 'written_off')->where('document_type', 'stock_off_act')->exists());
        $this->assertSame(bcsub($stockBefore, '1', 3), (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));

        $event = OnecOutboxEvent::query()->where('event_type', 'stock_off.posted')->sole();
        $this->assertSame('warehouse', $event->feed);
        $this->assertSame('damaged_at_receipt', $event->payload['reason']);
        $this->assertSame($receipt->uuid, $event->payload['goods_receipt']['crm_id']);
        $this->assertNull($event->payload['lines'][0]['serials'], 'No serials to 1C by default (DEC-19).');

        $this->expectOffError('act_locked', fn () => app(ManageStockOffAct::class)->addLine($act->fresh(), $item, '1'));
        $this->assertSame(
            ['draft', 'submitted', 'approved', 'posted'],
            $act->transitions()->pluck('to_status')->all(),
        );
    }

    public function test_value_limit_routes_approval_to_ceo_or_finance(): void
    {
        $item = Item::factory()->create(['is_serialized' => false]);
        ItemStock::factory()->create(['item_id' => $item->id, 'warehouse_id' => $this->warehouse->id, 'quantity' => '10', 'cost_minor' => 1_000_000, 'cost_currency' => 'UZS']);
        Setting::putMany(['stock_off.high_approval_from_minor' => (string) json_encode(['UZS' => 500_000])]);
        $manage = app(ManageStockOffAct::class);
        $act = $manage->create(['warehouse_id' => $this->warehouse->id, 'reason' => 'lost'], $this->stock);
        $line = $manage->addLine($act, $item, '3');

        $this->assertSame(100_000, $line->fresh()->unit_cost_minor);
        $this->assertSame(300_000, $line->fresh()->value_minor);
        $this->assertSame('head', $act->fresh()->approval_level);

        $manage->updateQuantity($line->fresh(), '6');
        $this->assertSame('high', $act->fresh()->approval_level);
        $this->assertSame(['UZS' => 600_000], $act->fresh()->totals);

        $moves = app(TransitionStockOffAct::class);
        $moves->handle($act->fresh(), StockOffStatus::Submitted, $this->stock);
        $this->assertFalse(TransitionStockOffAct::allows($this->head, $act->fresh(), StockOffStatus::Approved), 'Above the limit the warehouse head does not approve.');
        $moves->handle($act->fresh(), StockOffStatus::Approved, User::factory()->finance()->create());
        $moves->handle($act->fresh(), StockOffStatus::Posted, $this->head);
        $this->assertSame('4.000', (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));
    }

    public function test_serial_picking_rules_and_cancel_releases_serials(): void
    {
        $item = $this->serializedItem($this->warehouse, '10');
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['PK-001', 'PK-002', 'PK-003']);
        ItemSerial::query()->where('serial_normalized', 'PK-003')->update(['status' => SerialStatus::Sold->value]);
        $manage = app(ManageStockOffAct::class);
        $act = $manage->create(['warehouse_id' => $this->warehouse->id, 'reason' => 'damaged_in_storage'], $this->stock);
        $line = $manage->addLine($act, $item, '5');
        $this->assertSame('0.000', (string) $line->quantity, 'Serial-tracked lines count picked serials.');

        $results = $manage->addSerials($line, ['PK-001', 'PK-003', 'NOPE-1']);
        $this->assertSame(['ok', 'not_on_hand', 'not_found'], array_column($results, 'result'));
        $this->assertSame('1.000', (string) $line->fresh()->quantity);

        $other = $manage->create(['warehouse_id' => $this->warehouse->id, 'reason' => 'theft'], $this->stock);
        $otherLine = $manage->addLine($other, $item, '1');
        $this->assertSame('on_other_act', $manage->addSerials($otherLine, ['PK-001'])[0]['result']);

        $moves = app(TransitionStockOffAct::class);
        $this->expectOffError('reason_required', fn () => $moves->handle($act->fresh(), StockOffStatus::Cancelled, $this->stock));
        $moves->handle($act->fresh(), StockOffStatus::Cancelled, $this->stock, 'Found intact');
        $this->assertSame('ok', $manage->addSerials($otherLine->fresh(), ['PK-001'])[0]['result']);

        $elsewhere = Warehouse::factory()->create();
        $far = $manage->create(['warehouse_id' => $elsewhere->id, 'reason' => 'lost'], $this->stock);
        $this->assertSame('wrong_warehouse', $manage->addSerials($manage->addLine($far, $item, '1'), ['PK-002'])[0]['result']);
    }

    public function test_reject_rework_note_for_other_and_claims(): void
    {
        $item = Item::factory()->create(['is_serialized' => false]);
        $manage = app(ManageStockOffAct::class);
        $moves = app(TransitionStockOffAct::class);
        $act = $manage->create(['warehouse_id' => $this->warehouse->id, 'reason' => 'other'], $this->stock);
        $this->expectOffError('lines_incomplete', fn () => $moves->handle($act, StockOffStatus::Submitted, $this->stock));
        $manage->addLine($act, $item, '2');
        $this->expectOffError('note_required', fn () => $moves->handle($act->fresh(), StockOffStatus::Submitted, $this->stock));
        $manage->updateHeader($act->fresh(), ['note' => 'Water leak']);
        $moves->handle($act->fresh(), StockOffStatus::Submitted, $this->stock);
        $this->expectOffError('reason_required', fn () => $moves->handle($act->fresh(), StockOffStatus::Rejected, $this->head));
        $moves->handle($act->fresh(), StockOffStatus::Rejected, $this->head, 'Need photos');
        $moves->handle($act->fresh(), StockOffStatus::Draft, $this->stock);
        $this->assertSame(StockOffStatus::Draft, $act->fresh()->status);

        // Claims belong to receipt damage.
        $this->assertFalse($this->stock->can('manageClaim', $act->fresh()));
        $receiptAct = $manage->create(['warehouse_id' => $this->warehouse->id, 'reason' => 'damaged_at_receipt', 'goods_receipt_id' => $this->receiptFromOneC($this->warehouse, [[$item, '1']])->id], $this->stock);
        $this->assertTrue(User::factory()->staff()->inDepartment('purchase')->create()->can('manageClaim', $receiptAct));
        $this->expectOffError('claim_ref_required', fn () => $manage->updateClaim($receiptAct, ['claim_status' => 'open']));
        $manage->updateClaim($receiptAct, ['claim_status' => 'open', 'claim_ref' => 'CL-77']);
        $manage->updateClaim($receiptAct->fresh(), ['claim_status' => 'settled']);
        $this->assertNotNull($receiptAct->fresh()->claim_settled_at);
        $this->assertSame('CL-77', $receiptAct->fresh()->claim_ref);
    }

    public function test_screens_print_evidence_and_permissions(): void
    {
        Storage::fake('local');
        $item = Item::factory()->create(['is_serialized' => false, 'name' => 'Cable box']);

        Livewire::actingAs($this->stock)->test(StockOffActs::class)
            ->assertSee(__('stock_off.intro'))
            ->set('creating', true)
            ->set('form.reason', 'expired')
            ->call('create')
            ->assertHasNoErrors();
        $act = StockOffAct::query()->sole();

        Livewire::actingAs($this->stock)->test(StockOffActShow::class, ['act' => $act])
            ->assertSeeHtml('data-stock-off-stepper')
            ->call('addItem', $item->id)
            ->assertSee('Cable box')
            ->set('evidence', UploadedFile::fake()->image('photo.png'))
            ->call('uploadEvidence')
            ->assertHasNoErrors()
            ->call('move', 'submitted')
            ->assertSee(__('stock_off.own_act_hint'));

        $attachment = $act->attachments()->sole();
        $this->actingAs(User::factory()->accountant()->create())->get(route('attachments.download', $attachment))->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get(route('attachments.download', $attachment))->assertForbidden();

        $this->actingAs($this->head)->get(route('stock-off.print', ['act' => $act, 'lang' => 'ru']))->assertOk()->assertSee(__('documents.stock_off.title', [], 'ru'));
        $this->actingAs(User::factory()->sales()->create())->get(route('stock-off.index'))->assertForbidden();
        $this->assertFalse(User::factory()->admin()->create()->can('create', StockOffAct::class));
        $this->actingAs($this->head)->get(route('stock-off.index'))->assertOk()->assertSee($act->number);
    }

    private function expectOffError(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected stock-off error '.$key);
        } catch (StockOffException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }
}
