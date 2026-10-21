<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\ManageGoodsReceipt;
use App\Actions\Stock\PostGoodsReceipt;
use App\Actions\Stock\TransitionGoodsReceipt;
use App\Enums\DepartmentRole;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SerialStatus;
use App\Livewire\Stock\GoodsReceiptShow;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\OnecOutboxEvent;
use App\Models\Payment;
use App\Models\SerialEvent;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 06 21.4 goods receipts and BR-SN.1/3/4/5: AC-S1, AC-S2, AC-S3, discrepancies, AC-S16 (receipts).
 */
class GoodsReceiptTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = User::factory()->stockManager()->create();
        $this->warehouse = Warehouse::factory()->create(['name' => 'Main']);
    }

    /**
     * A 1C receipt started by the stock manager.
     *
     * @param  list<array{0: Item, 1: string}>  $lines
     */
    private function startedReceipt(array $lines): GoodsReceipt
    {
        $receipt = $this->receiptFromOneC($this->warehouse, $lines);

        return app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock);
    }

    /** AC-S1 */
    public function test_posting_needs_one_serial_per_received_unit(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $receipt = $this->startedReceipt([[$item, '5']]);
        $line = $receipt->lines()->firstOrFail();
        app(AddReceiptSerials::class)->handle($line, ['A001', 'A002', 'A003', 'A004'], $this->stock);

        $this->assertSame('4.000', (string) $line->fresh()->received_qty, 'Received follows the serials entered.');

        try {
            app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::Checked, $this->stock);
            $this->fail('A shortage of one unit needs a reason.');
        } catch (StockActionException $exception) {
            $this->assertSame('discrepancy_reason_required', $exception->key);
        }

        app(AddReceiptSerials::class)->handle($line, ['A005'], $this->stock);
        app(TransitionGoodsReceipt::class)->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock);
        app(PostGoodsReceipt::class)->handle($receipt->fresh(), $this->stock);

        $this->assertSame(5, ItemSerial::query()->where('item_id', $item->id)->where('status', SerialStatus::Available)->where('warehouse_id', $this->warehouse->id)->count());
        $this->assertSame(5, SerialEvent::query()->where('event', 'registered')->count());
        $this->assertMatchesRegularExpression('/^GR-\d{4}-\d{5}$/', $receipt->number);
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'goods_receipt.posted')->where('feed', 'warehouse')->exists());
        $this->assertNull(OnecOutboxEvent::query()->sole()->payload['lines'][0]['serials'], 'No serials to 1C by default.');
    }

    /** AC-S2 */
    public function test_normalization_duplicates_and_cyrillic(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $line = $this->startedReceipt([[$item, '3']])->lines()->firstOrFail();

        $results = app(AddReceiptSerials::class)->handle($line, [' sn-0001 ', 'sn-0001', 'SN 0002', 'СН0003'], $this->stock);

        $this->assertSame(['ok', 'duplicate_in_file', 'ok', 'cyrillic'], array_column($results, 'result'));
        $this->assertSame(['SN-0001', 'SN-0001', 'SN0002', null], array_column($results, 'serial'));
        $this->assertStringContainsString(__('stock.results.duplicate_in_file', ['row' => 1]), $results[1]['message']);
        $this->assertSame(['SN-0001', 'SN0002'], $line->serialRows()->pluck('serial_number')->all());

        $again = app(AddReceiptSerials::class)->handle($line, ['SN0002'], $this->stock);
        $this->assertSame('already_in_document', $again[0]['result']);
    }

    /** AC-S3 */
    public function test_registered_serials_are_refused_with_the_original_receipt(): void
    {
        $itemA = $this->serializedItem($this->warehouse, '10', ['sku' => 'A-1', 'name' => 'Item A']);
        $itemB = $this->serializedItem($this->warehouse, '10', ['sku' => 'B-1', 'name' => 'Item B']);
        $first = $this->receiveSerials($this->stock, $this->warehouse, $itemA, ['X100']);
        $this->receiveSerials($this->stock, $this->warehouse, $itemB, ['Y200']);

        $line = $this->startedReceipt([[$itemA, '2']])->lines()->firstOrFail();

        $results = app(AddReceiptSerials::class)->handle($line, ['X100', 'Y200'], $this->stock);
        $this->assertSame(['already_registered', 'ok'], array_column($results, 'result'), 'Per-item uniqueness by default.');
        $this->assertStringContainsString($first->number, $results[0]['message']);

        $line->serialRows()->get()->each->delete();
        Setting::putMany(['serials.global_uniqueness' => '1']);
        $global = app(AddReceiptSerials::class)->handle($line->fresh(), ['Y200'], $this->stock);
        $this->assertSame('belongs_to_other_item', $global[0]['result']);
        $this->assertStringContainsString('Item B', $global[0]['message']);
    }

    public function test_discrepancy_needs_a_reason_and_over_receipt_the_warehouse_head(): void
    {
        $item = Item::factory()->create(['is_serialized' => false]);
        $manage = app(ManageGoodsReceipt::class);
        $receipt = $this->startedReceipt([[$item, '10']]);
        $line = $receipt->lines()->firstOrFail();
        $manage->updateLine($line, ['received_qty' => '12']);

        try {
            app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::Checked, $this->stock);
            $this->fail('A reason is required.');
        } catch (StockActionException $exception) {
            $this->assertSame('discrepancy_reason_required', $exception->key);
        }

        $manage->updateLine($line->fresh(), ['discrepancy_reason' => 'over']);
        app(TransitionGoodsReceipt::class)->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock);

        try {
            app(PostGoodsReceipt::class)->handle($receipt->fresh(), $this->stock);
            $this->fail('Over-receipt needs the warehouse head.');
        } catch (StockActionException $exception) {
            $this->assertSame('over_receipt_needs_head', $exception->key);
        }

        $head = User::factory()->staff()->inDepartment('warehouse', DepartmentRole::Head)->create();
        app(PostGoodsReceipt::class)->handle($receipt->fresh(), $head);
        $this->assertSame('posted', $receipt->fresh()->status->value);
    }

    /** AC-S16 (receipts) */
    public function test_only_stock_roles_receive_goods(): void
    {
        $receipt = $this->startedReceipt([[Item::factory()->create(), '1']]);

        $this->assertFalse(User::factory()->accountant()->create()->can('post', $receipt));
        $this->assertTrue(User::factory()->accountant()->create()->can('view', $receipt));
        $this->assertFalse(User::factory()->sales()->create()->can('viewAny', GoodsReceipt::class));
        $this->assertFalse(User::factory()->admin()->create()->can('create', GoodsReceipt::class));
        $this->assertFalse($this->stock->can('create', Payment::class), 'A stock manager never records money (SoD-S1).');

        Livewire::actingAs($this->stock)->test(GoodsReceiptShow::class, ['receipt' => $receipt])
            ->set('itemSearch', 'Router')
            ->assertOk();
    }
}
