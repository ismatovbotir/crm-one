<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\ManageGoodsReceipt;
use App\Actions\Stock\ManageSerials;
use App\Actions\Stock\PostGoodsReceipt;
use App\Actions\Stock\TransitionGoodsReceipt;
use App\Enums\DepartmentRole;
use App\Enums\GoodsReceiptStatus;
use App\Enums\IntegrationAbility;
use App\Enums\SerialStatus;
use App\Livewire\Stock\GoodsReceipts;
use App\Livewire\Stock\GoodsReceiptShow;
use App\Livewire\Stock\SerialEntry;
use App\Models\GoodsReceipt;
use App\Models\InboundBatch;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\OnecOutboxEvent;
use App\Models\Partner;
use App\Models\StatusTransition;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OneC\Inbound\GoodsReceiptImporter;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\Import\SerialImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 06 section 26, Spec 02 rev. 5 section 10.6: supplier goods receipts originate in
 * 1C (POST /goods-receipts, PUT /goods-receipts/{crmId}); the stock manager completes them through
 * the GoodsReceiptStatus workflow; the posted result is available for 1C to pull.
 */
class GoodsReceiptFromOneCTest extends TestCase
{
    use BuildsStock, InteractsWithOneCApi, RefreshDatabase;

    private User $stock;

    private User $head;

    private Warehouse $warehouse;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableInbound();
        $this->issueToken([IntegrationAbility::WarehouseWrite->value]);
        User::factory()->manager()->create();
        $this->stock = User::factory()->stockManager()->create();
        $this->head = User::factory()->staff()->inDepartment('warehouse', DepartmentRole::Head)->create();
        $this->warehouse = Warehouse::factory()->create(['name' => 'Main']);
        $this->unit = Unit::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return [
            'id' => $this->guid('doc-1'),
            'number' => 'ПТ-000318',
            'date' => '2026-10-19',
            'warehouse_id' => $this->warehouse->external_id,
            'supplier' => ['tax_id' => '305112233', 'name' => 'Net Distribution', 'full_name' => 'Net Distribution MChJ'],
            'lines' => [
                ['line_id' => $this->guid('line-1'), 'item' => ['id' => $this->guid('item-1'), 'code' => 'RB-1', 'name' => 'Router RB', 'unit_id' => $this->unit->external_id], 'expected_qty' => '10'],
            ],
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $receipts
     * @return array<string, mixed>
     */
    private function push(array $receipts, ?string $batchId = null): array
    {
        return ['batch_id' => $batchId ?? (string) Str::uuid(), 'sent_at' => '2026-10-19T10:00:00+05:00', 'receipts' => $receipts];
    }

    private function receipt(): GoodsReceipt
    {
        return GoodsReceipt::query()->where('external_id', $this->guid('doc-1'))->firstOrFail();
    }

    public function test_push_creates_the_receipt_supplier_and_item_and_answers_with_the_crm_link(): void
    {
        $response = $this->onec('POST', '/goods-receipts', $this->push([$this->record()]))
            ->assertOk()
            ->assertJsonPath('results.0.result', 'created')
            ->assertJsonPath('results.0.id', $this->guid('doc-1'))
            ->assertJsonPath('results.0.version', 1)
            ->assertJsonPath('results.0.created.partner', true)
            ->assertJsonPath('results.0.created.items', ['RB-1']);

        $receipt = $this->receipt();
        $response->assertJsonPath('results.0.crm_id', $receipt->uuid)->assertJsonPath('results.0.number', $receipt->number);
        $this->assertSame(GoodsReceiptStatus::ReceivedFromOneC, $receipt->status);
        $this->assertTrue($receipt->isFromOneC());
        $this->assertSame('ПТ-000318', $receipt->external_number);
        $this->assertSame('10.000', (string) $receipt->lines()->sole()->expected_qty);
        $this->assertSame('10.000', (string) $receipt->lines()->sole()->received_qty, 'Received starts at expected.');

        $supplier = Partner::query()->where('tax_id', '305112233')->sole();
        $this->assertTrue($supplier->is_supplier);
        $this->assertFalse($supplier->is_customer);
        $this->assertTrue($supplier->sync_with_1c);
        $this->assertTrue($supplier->needs_review);
        $this->assertSame($supplier->id, $receipt->supplier_partner_id);

        $item = Item::query()->where('external_id', $this->guid('item-1'))->sole();
        $this->assertTrue($item->needs_review);
        $this->assertSame('created_from_1c_receipt', $item->review_reason);

        $this->assertSame('goods_receipts', InboundBatch::query()->sole()->endpoint);
        $this->assertSame('received_from_1c', StatusTransition::query()->where('subject_type', 'goods_receipt')->sole()->to_status);
    }

    public function test_replay_same_content_and_token_scope(): void
    {
        $body = $this->push([$this->record()]);
        $this->onec('POST', '/goods-receipts', $body)->assertOk();
        $this->onec('POST', '/goods-receipts', $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->onec('POST', '/goods-receipts', $this->push([$this->record()]))->assertOk()->assertJsonPath('results.0.result', 'unchanged');
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(1, $this->receipt()->sync_version);

        $this->issueToken([IntegrationAbility::WarehouseRead->value]);
        $this->onec('POST', '/goods-receipts', $this->push([$this->record()]))->assertForbidden();
    }

    public function test_a_receipt_created_by_a_parallel_push_is_updated_instead_of_failing_on_the_unique_id(): void
    {
        $this->onec('POST', '/goods-receipts', $this->push([$this->record()]))->assertOk();

        // The first lookup misses (the other push had not committed yet); the insert then hits the unique index.
        $importer = new class extends GoodsReceiptImporter
        {
            private bool $missed = false;

            protected function lockedReceipt(string $column, string $value): ?GoodsReceipt
            {
                if (! $this->missed) {
                    $this->missed = true;

                    return null;
                }

                return parent::lockedReceipt($column, $value);
            }
        };

        $result = $importer->upsert($this->record(['number' => 'ПТ-000319']), 0);

        $this->assertSame('updated', $result['result']);
        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(2, $this->receipt()->sync_version);
        $this->assertSame('ПТ-000319', $this->receipt()->external_number);
    }

    public function test_tax_id_is_required_and_a_rejected_receipt_leaves_nothing_behind(): void
    {
        $this->onec('POST', '/goods-receipts', $this->push([$this->record(['supplier' => ['name' => 'No INN']])]))
            ->assertStatus(207)
            ->assertJsonPath('results.0.result', 'failed')
            ->assertJsonPath('results.0.errors.0.code', 'tax_id_required');

        $unknownUnit = $this->record(['lines' => [['line_id' => $this->guid('l'), 'item' => ['id' => $this->guid('item-x'), 'code' => 'X', 'name' => 'X', 'unit_id' => $this->guid('no-unit')], 'expected_qty' => '1']]]);
        $this->onec('POST', '/goods-receipts', $this->push([$unknownUnit]))
            ->assertStatus(207)
            ->assertJsonPath('results.0.errors.0.code', 'unknown_unit');

        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(0, Partner::query()->where('tax_id', '305112233')->count(), 'No partial create.');
    }

    public function test_update_by_crm_id_with_version_and_stale_checks(): void
    {
        $this->onec('POST', '/goods-receipts', $this->push([$this->record(['updated_at' => '2026-10-19T09:00:00+05:00'])]))->assertOk();
        $receipt = $this->receipt();
        $changed = $this->record(['version' => 1, 'updated_at' => '2026-10-19T09:30:00+05:00', 'lines' => [['line_id' => $this->guid('line-1'), 'item' => ['id' => $this->guid('item-1')], 'expected_qty' => '12']]]);

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, $changed)
            ->assertOk()
            ->assertJsonPath('data.result', 'updated')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.crm_id', $receipt->uuid);
        $this->assertSame('12.000', (string) $receipt->lines()->sole()->expected_qty);

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, [...$changed, 'version' => 1, 'lines' => [['line_id' => $this->guid('line-1'), 'item' => ['id' => $this->guid('item-1')], 'expected_qty' => '15']]])
            ->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'stale_version');

        $this->onec('POST', '/goods-receipts', $this->push([[...$changed, 'crm_id' => $receipt->uuid, 'version' => 2, 'updated_at' => '2026-10-19T09:10:00+05:00', 'lines' => [['line_id' => $this->guid('line-1'), 'item' => ['id' => $this->guid('item-1')], 'expected_qty' => '15']]]]))
            ->assertStatus(207)
            ->assertJsonPath('results.0.errors.0.code', 'stale_update');

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, $this->record(['id' => $this->guid('other-doc')]))
            ->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'id_mismatch');

        $this->assertSame('12.000', (string) $receipt->lines()->sole()->expected_qty);
    }

    public function test_a_posted_receipt_is_never_rewritten_and_is_flagged_for_review(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $receipt = $this->receiveSerials($this->stock, $this->warehouse, $item, ['SN-1001', 'SN-1002']);
        $line = $receipt->lines()->sole();
        $record = [
            'id' => $receipt->external_id, 'number' => 'NEW', 'date' => '2026-10-19', 'warehouse_id' => $this->warehouse->external_id,
            'supplier' => ['tax_id' => $receipt->supplierPartner->tax_id],
            'lines' => [['line_id' => $line->external_line_id, 'item' => ['id' => $item->external_id], 'expected_qty' => '1']],
        ];

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, $record)
            ->assertStatus(409)
            ->assertJsonPath('data.errors.0.code', 'receipt_posted')
            ->assertJsonPath('data.review_flagged', true);

        $fresh = $receipt->fresh();
        $this->assertSame(GoodsReceiptStatus::Posted, $fresh->status);
        $this->assertTrue($fresh->needs_review);
        $this->assertSame('onec_update_after_posting', $fresh->review_reason);
        $this->assertSame('2.000', (string) $line->fresh()->expected_qty);
        $this->assertTrue($fresh->activities()->where('event', 'onec_update_rejected')->exists());

        $this->assertFalse($this->stock->can('resolveReview', $fresh));
        app(ManageGoodsReceipt::class)->resolveReview($fresh, $this->head, 'Correction CR-1 proposed');
        $this->assertFalse($fresh->fresh()->needs_review);
    }

    public function test_lines_with_serials_keep_item_and_quantity_and_cannot_be_removed(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $other = Item::factory()->create();
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '3'], [$other, '5']]);
        app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock);
        $lines = $receipt->lines()->get();
        app(AddReceiptSerials::class)->handle($lines[0], ['SN-2001', 'SN-2002'], $this->stock);
        $base = [
            'id' => $receipt->external_id, 'number' => 'ПТ-1', 'date' => '2026-10-19', 'warehouse_id' => $this->warehouse->external_id,
            'supplier' => ['tax_id' => $receipt->supplierPartner->tax_id],
        ];
        $line = fn (int $index, Item $lineItem, string $qty): array => ['line_id' => $lines[$index]->external_line_id, 'item' => ['id' => $lineItem->external_id], 'expected_qty' => $qty];

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, [...$base, 'lines' => [$line(0, $other, '3'), $line(1, $other, '5')]])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'line_has_serials');

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, [...$base, 'lines' => [$line(1, $other, '5')]])->assertStatus(409)->assertJsonPath('data.errors.0.code', 'line_has_serials');

        // Quantities may change (1 expected with 2 serials = an overage shown at checking).
        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, [...$base, 'lines' => [$line(0, $item, '1'), $line(1, $other, '7')]])->assertOk()->assertJsonPath('data.result', 'updated');
        $this->assertSame(['1.000', '7.000'], $receipt->lines()->pluck('expected_qty')->map(fn ($qty): string => (string) $qty)->all());

        // Serialized received / damaged quantities are counted, never typed.
        app(ManageGoodsReceipt::class)->updateLine($lines[0]->fresh(), ['received_qty' => '9', 'damaged_qty' => '1']);
        $this->assertSame('2.000', (string) $lines[0]->fresh()->received_qty);
        $this->assertSame('0.000', (string) $lines[0]->fresh()->damaged_qty);
        $this->assertSame('5.000', (string) $lines[1]->fresh()->received_qty, 'Non-serialized lines keep the 1C quantity until edited.');

        $this->expectStockError('discrepancy_reason_required', fn () => app(TransitionGoodsReceipt::class)->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock));
    }

    public function test_delete_in_1c_cancels_or_becomes_a_cancel_request_when_serials_are_entered(): void
    {
        $this->onec('POST', '/goods-receipts', $this->push([$this->record()]))->assertOk();
        $this->onec('POST', '/goods-receipts', $this->push([$this->record(['is_deleted' => true])]))->assertOk()->assertJsonPath('results.0.result', 'cancelled');
        $this->assertSame(GoodsReceiptStatus::Cancelled, $this->receipt()->status);
        $this->assertSame(0, OnecOutboxEvent::query()->count(), '1C knows about its own delete.');

        $item = $this->serializedItem($this->warehouse);
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '2']]);
        app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock);
        app(AddReceiptSerials::class)->handle($receipt->lines()->sole(), ['SN-3001'], $this->stock);
        $record = ['id' => $receipt->external_id, 'number' => 'X', 'date' => '2026-10-19', 'warehouse_id' => $this->warehouse->external_id, 'supplier' => ['tax_id' => $receipt->supplierPartner->tax_id], 'lines' => [['line_id' => $receipt->lines()->sole()->external_line_id, 'item' => ['id' => $item->external_id], 'expected_qty' => '2']], 'is_deleted' => true];

        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, $record)->assertOk()->assertJsonPath('data.result', 'cancel_requested')->assertJsonPath('data.review_flagged', true);
        $receipt->refresh();
        $this->assertSame(GoodsReceiptStatus::InProgress, $receipt->status);
        $this->assertSame('cancel_requested_by_1c', $receipt->review_reason);

        $this->assertFalse(TransitionGoodsReceipt::allows($this->stock, $receipt, GoodsReceiptStatus::Cancelled), 'Only the warehouse head cancels a 1C receipt.');
        app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::Cancelled, $this->head, 'Deleted in 1C');
        $this->assertSame(GoodsReceiptStatus::Cancelled, $receipt->fresh()->status);
        $this->assertFalse($receipt->fresh()->needs_review);
        $this->assertSame('goods_receipt.cancelled', OnecOutboxEvent::query()->sole()->event_type);
    }

    public function test_workflow_transitions_and_who_may_move_them(): void
    {
        $item = Item::factory()->create();
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '4']]);
        $transition = app(TransitionGoodsReceipt::class);

        $this->assertFalse($this->stock->can('update', $receipt), 'Start receiving first.');
        $this->expectStockError('receipt_check_first', fn () => app(PostGoodsReceipt::class)->handle($transition->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock), $this->stock));
        $this->expectStockError('receipt_reason_required', fn () => $transition->handle($receipt->fresh(), GoodsReceiptStatus::OnHold, $this->stock));

        $transition->handle($receipt->fresh(), GoodsReceiptStatus::OnHold, $this->stock, 'Pallet missing');
        $this->assertFalse(TransitionGoodsReceipt::allows($this->stock, $receipt->fresh(), GoodsReceiptStatus::InProgress), 'Only the warehouse head releases a hold.');
        $this->expectStockError('receipt_transition_forbidden', fn () => $transition->handle($receipt->fresh(), GoodsReceiptStatus::InProgress, $this->stock));
        $transition->handle($receipt->fresh(), GoodsReceiptStatus::InProgress, $this->head);

        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock);
        $this->assertFalse($this->stock->can('update', $receipt->fresh()), 'A checked receipt is frozen.');

        // 1C changes a checked receipt: it goes back to the stock manager.
        $record = ['id' => $receipt->external_id, 'number' => 'X', 'date' => '2026-10-19', 'warehouse_id' => $this->warehouse->external_id, 'supplier' => ['tax_id' => $receipt->supplierPartner->tax_id], 'lines' => [['line_id' => $receipt->lines()->sole()->external_line_id, 'item' => ['id' => $item->external_id], 'expected_qty' => '4']]];
        $this->onec('PUT', '/goods-receipts/'.$receipt->uuid, $record)->assertOk()->assertJsonPath('data.result', 'updated');
        $this->assertSame(GoodsReceiptStatus::InProgress, $receipt->fresh()->status);

        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock);
        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Posted, $this->stock);
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->fresh()->status);
        $this->expectStockError('receipt_invalid_transition', fn () => $transition->handle($receipt->fresh(), GoodsReceiptStatus::Cancelled, $this->head, 'late'));

        $event = OnecOutboxEvent::query()->sole();
        $this->assertSame('goods_receipt.posted', $event->event_type);
        $this->assertSame($receipt->external_id, $event->payload['onec_document']['id']);
        $this->assertSame('0.000', $event->payload['lines'][0]['discrepancy']);
        $this->assertSame('4.000', $event->payload['lines'][0]['expected']);
        $this->assertSame(
            ['received_from_1c', 'in_progress', 'on_hold', 'in_progress', 'checked', 'in_progress', 'checked', 'posted'],
            StatusTransition::query()->where('subject_type', 'goods_receipt')->where('subject_id', $receipt->id)->orderBy('id')->pluck('to_status')->all(),
        );
    }

    public function test_damage_is_flagged_per_serial_and_only_flagged_serials_are_quarantined(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '3']]);
        $transition = app(TransitionGoodsReceipt::class);
        $transition->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock);
        $line = $receipt->lines()->sole();
        $this->assertSame('0.000', (string) $line->received_qty, 'Serialized received starts at zero.');

        // Paste with a damaged column: "serial; damaged; note".
        Livewire::actingAs($this->stock)->test(SerialEntry::class, ['lineType' => 'goods_receipt_line', 'lineId' => $line->id])
            ->set('paste', "SN-4001\nSN-4002; damaged\nSN-4003")
            ->call('addPaste')
            ->assertSee(__('stock.entry.counter', ['count' => 3, 'expected' => '3']));

        $line->refresh();
        $this->assertSame('3.000', (string) $line->received_qty);
        $this->assertSame('1.000', (string) $line->damaged_qty);
        $this->assertSame('mixed', $line->condition);

        $this->expectStockError('damaged_note_required', fn () => $transition->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock));
        $row = $line->serialRows()->where('serial_number', 'SN-4002')->sole();
        app(ManageSerials::class)->markDamaged($row, true, 'Box crushed', $this->stock);
        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Checked, $this->stock);
        $transition->handle($receipt->fresh(), GoodsReceiptStatus::Posted, $this->stock);

        $this->assertSame(['SN-4002'], ItemSerial::query()->where('item_id', $item->id)->where('status', SerialStatus::Quarantined)->pluck('serial_normalized')->all());
        $this->assertSame(2, ItemSerial::query()->where('item_id', $item->id)->where('status', SerialStatus::Available)->count());
        $payload = OnecOutboxEvent::query()->sole()->payload['lines'][0];
        $this->assertSame(['3.000', '1.000', '0.000'], [$payload['received'], $payload['damaged'], $payload['discrepancy']]);
        $this->expectStockError('row_locked', fn () => app(ManageSerials::class)->markDamaged($row->fresh(), false, null, $this->stock));
    }

    public function test_import_file_damaged_column_and_toggle(): void
    {
        $item = $this->serializedItem($this->warehouse);
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '2']]);
        app(TransitionGoodsReceipt::class)->handle($receipt, GoodsReceiptStatus::InProgress, $this->stock);
        $line = $receipt->lines()->sole();
        $file = UploadedFile::fake()->createWithContent('serials.csv', "serial_number;note;damaged\nSN-5001;;\nSN-5002;Scratched;yes\n");

        $importer = app(SerialImporter::class);
        $batch = $importer->preview($line, $file, $this->stock);
        $importer->commit($batch, $line, 'all_or_nothing', $this->stock);
        $this->assertSame(['SN-5002'], $line->serialRows()->where('is_damaged', true)->pluck('serial_number')->all());
        $this->assertSame('Scratched', $line->serialRows()->where('is_damaged', true)->value('damage_note'));

        $first = $line->serialRows()->where('serial_number', 'SN-5001')->sole();
        Livewire::actingAs($this->stock)->test(SerialEntry::class, ['lineType' => 'goods_receipt_line', 'lineId' => $line->id])
            ->call('toggleDamaged', $first->id)
            ->assertSeeHtml('data-serial-damaged');
        $this->assertSame('2.000', (string) $line->fresh()->damaged_qty);
        $this->assertSame('damaged', $line->fresh()->condition);

        app(ManageSerials::class)->removeRow($first->fresh(), $this->stock);
        $this->assertSame(['1.000', '1.000'], [(string) $line->fresh()->received_qty, (string) $line->fresh()->damaged_qty]);
    }

    public function test_crm_creates_only_returns_and_opening_balances_with_the_short_path(): void
    {
        $manage = app(ManageGoodsReceipt::class);
        $this->expectStockError('receipt_from_1c_only', fn () => $manage->create(['source_type' => 'supplier_delivery', 'warehouse_id' => $this->warehouse->id], $this->stock));

        $item = Item::factory()->create();
        $receipt = $manage->create(['source_type' => 'opening_balance', 'warehouse_id' => $this->warehouse->id], $this->stock);
        $this->assertSame(GoodsReceiptStatus::InProgress, $receipt->status);
        $manage->addLine($receipt, $item, null, '3');
        $this->assertTrue(TransitionGoodsReceipt::allows($this->stock, $receipt, GoodsReceiptStatus::Posted));
        $this->assertNull(TransitionGoodsReceipt::ability($receipt, GoodsReceiptStatus::Checked), 'No checked step on the short path.');
        app(PostGoodsReceipt::class)->handle($receipt, $this->stock);
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->fresh()->status);

        $oneC = $this->receiptFromOneC($this->warehouse, [[$item, '1']]);
        app(TransitionGoodsReceipt::class)->handle($oneC, GoodsReceiptStatus::InProgress, $this->stock);
        $this->expectStockError('receipt_lines_from_1c', fn () => $manage->addLine($oneC->fresh(), $item, null, '1'));
        $this->expectStockError('receipt_lines_from_1c', fn () => $manage->removeLine($oneC->lines()->sole()));
    }

    public function test_screens_show_origin_filters_stepper_and_moves(): void
    {
        $item = Item::factory()->create();
        $receipt = $this->receiptFromOneC($this->warehouse, [[$item, '2']]);

        Livewire::actingAs($this->stock)->test(GoodsReceipts::class)
            ->assertSee(__('stock.receipts.from_1c_hint'))
            ->assertSee(__('stock.receipt_status.received_from_1c'))
            ->assertDontSee('value="supplier_delivery"', false)
            ->set('origin', 'crm')
            ->assertDontSee($receipt->number)
            ->set('origin', 'onec')
            ->assertSee($receipt->number);

        Livewire::actingAs($this->stock)->test(GoodsReceiptShow::class, ['receipt' => $receipt])
            ->assertSee(__('stock.receipts.moves.start'))
            ->assertDontSee(__('stock.receipts.moves.posted'))
            ->call('move', 'in_progress')
            ->assertSee(__('stock.receipts.moves.checked'))
            ->assertSeeHtml('data-receipt-stepper');

        $this->assertSame(GoodsReceiptStatus::InProgress, $receipt->fresh()->status);

        Livewire::actingAs(User::factory()->accountant()->create())->test(GoodsReceiptShow::class, ['receipt' => $receipt])
            ->assertDontSee(__('stock.receipts.moves.checked'))
            ->call('move', 'checked')
            ->assertForbidden();
    }

    private function expectStockError(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected stock error '.$key);
        } catch (StockActionException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }
}
