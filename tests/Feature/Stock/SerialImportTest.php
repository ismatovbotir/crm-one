<?php

namespace Tests\Feature\Stock;

use App\Actions\Stock\ManageGoodsReceipt;
use App\Enums\IntegrationAbility;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\Import\SerialImporter;
use App\Services\Stock\Reconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Concerns\BuildsStock;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 06 21.8 import (AC-S11, AC-S12), 21.11 reconciliation (AC-S13/S14 basics), warehouse feed.
 */
class SerialImportTest extends TestCase
{
    use BuildsStock, InteractsWithOneCApi, RefreshDatabase;

    private User $stock;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->stock = User::factory()->stockManager()->create();
        $this->warehouse = Warehouse::factory()->create();
    }

    private function receiptLine(int $received, ?Item $item = null): GoodsReceiptLine
    {
        $item ??= $this->serializedItem($this->warehouse, '20');
        $manage = app(ManageGoodsReceipt::class);
        $receipt = $manage->create(['source_type' => 'opening_balance', 'warehouse_id' => $this->warehouse->id], $this->stock);

        return $manage->addLine($receipt, $item, null, (string) $received);
    }

    /** AC-S11 */
    public function test_csv_preview_all_or_nothing_valid_only_and_idempotency(): void
    {
        $other = $this->serializedItem($this->warehouse, '5', ['sku' => 'OTHER', 'name' => 'Other item']);
        $this->receiveSerials($this->stock, $this->warehouse, $other, ['OTH001']);
        Setting::putMany(['serials.global_uniqueness' => '1']);
        $line = $this->receiptLine(10);

        $csv = mb_convert_encoding("Серийный номер;Примечание\nAA0001;\nAA0002;\nAA0002;\n1.23E+15;\nOTH001;\nAA0003;\nAA0004;\nAA0005;\nAA0006;\nAA0007;\n", 'Windows-1251', 'UTF-8');
        $file = UploadedFile::fake()->createWithContent('serials.csv', $csv);

        $batch = app(SerialImporter::class)->preview($line, $file, $this->stock);
        $this->assertSame(['Windows-1251', ';', 10, 7, 3], [$batch->encoding_detected, $batch->delimiter_detected, $batch->rows_total, $batch->rows_ok, $batch->rows_error]);
        $this->assertSame(['duplicate_in_file', 'scientific_notation', 'belongs_to_other_item'], $batch->rows()->where('result', '!=', 'ok')->pluck('result')->all());
        $this->assertSame(0, $line->serialRows()->count(), 'Preview writes nothing.');

        try {
            app(SerialImporter::class)->commit($batch, $line, 'all_or_nothing', $this->stock);
            $this->fail('All-or-nothing must be refused while errors exist.');
        } catch (StockActionException $exception) {
            $this->assertSame('import_has_errors', $exception->key);
        }

        app(SerialImporter::class)->commit($batch, $line, 'valid_only', $this->stock);
        $this->assertSame(7, $line->serialRows()->count());

        try {
            app(SerialImporter::class)->preview($line, UploadedFile::fake()->createWithContent('again.csv', $csv), $this->stock);
            $this->fail('The same file must not be imported twice.');
        } catch (StockActionException $exception) {
            $this->assertSame('import_already_committed', $exception->key);
        }
    }

    /** AC-S12 */
    public function test_xlsx_numeric_cells_formulas_and_limits(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['serial_number']));
        $writer->addRow(new Row([Cell::fromValue(123456)]));
        $writer->addRow(new Row([Cell::fromValue(12345678901234567.0)]));
        $writer->addRow(new Row([Cell::fromValue('=A2')]));
        $writer->addRow(Row::fromValues(['TXT0001']));
        $writer->close();

        $line = $this->receiptLine(2);
        $batch = app(SerialImporter::class)->preview($line, new UploadedFile($path, 'serials.xlsx', null, null, true), $this->stock);

        $this->assertSame(['numeric_cell', 'precision_lost', 'formula', 'ok'], $batch->rows()->pluck('result')->all());
        app(SerialImporter::class)->commit($batch, $line, 'valid_only', $this->stock);
        $this->assertSame(['123456', 'TXT0001'], $line->serialRows()->pluck('serial_number')->all());

        Setting::putMany(['serials.import_max_rows' => '1']);

        try {
            app(SerialImporter::class)->preview($this->receiptLine(1), UploadedFile::fake()->createWithContent('two.csv', "serial_number\nAB001\nAB002\n"), $this->stock);
            $this->fail('Row limit must apply.');
        } catch (StockActionException $exception) {
            $this->assertSame('import_too_many_rows', $exception->key);
        }

        $this->expectExceptionObject(new StockActionException('import_format'));
        app(SerialImporter::class)->preview($this->receiptLine(1), UploadedFile::fake()->createWithContent('evil.html', '<html></html>'), $this->stock);
    }

    /** AC-S13/S14 basics */
    public function test_reconciliation_flags_missing_serials(): void
    {
        $item = $this->serializedItem($this->warehouse, '10');
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['RC0001', 'RC0002', 'RC0003']);

        $row = collect(app(Reconciliation::class)->rows())->firstWhere('item.id', $item->id);
        $this->assertSame([3, '10.000'], [$row['crm'], $row['onec']]);
        $this->assertSame(-3, $row['lag'], 'The receipt is not yet acked by 1C.');
        $this->assertSame('-10.000', $row['discrepancy']);

        $this->actingAs($this->stock)->get(route('stock.reconciliation'))->assertOk()->assertSee(__('stock.reconciliation.missing'));
        $this->assertTrue(ItemSerial::query()->where('serial_normalized', 'RC0001')->exists());
    }

    public function test_goods_receipts_go_to_the_warehouse_feed(): void
    {
        $this->enableInbound();
        $item = $this->serializedItem($this->warehouse, '10');
        $this->receiveSerials($this->stock, $this->warehouse, $item, ['WF0001']);

        $this->issueToken([IntegrationAbility::OrdersRead->value]);
        $this->onec('GET', '/outbox/pending?feed=warehouse')->assertForbidden();
        $this->issueToken([IntegrationAbility::WarehouseRead->value]);
        $this->onec('GET', '/outbox/pending?feed=warehouse')->assertOk()->assertJsonPath('data.0.event_type', 'goods_receipt.posted');
    }
}
