<?php

namespace App\Actions\Stock;

use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\User;
use App\Services\Stock\SerialChecker;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Adds serials to a draft receipt line (Spec 06 21.7, BR-SN.4/5): one by one, pasted or from an
 * import. Each input is normalized and checked; valid ones become draft serial rows (the unique
 * `active_key` guards duplicates in the document); the serial itself is created when posting.
 * Returns the per-input results (ok rows were added).
 */
class AddReceiptSerials
{
    public function __construct(protected SerialChecker $checker, protected ManageGoodsReceipt $receipts) {}

    /**
     * @param  list<string>  $inputs
     * @param  array<string, string|null>  $damaged  normalized serial => damage note, for units flagged damaged (Spec 06 26.4)
     * @return list<array{raw: string, serial: string|null, result: string, message: string}>
     */
    public function handle(GoodsReceiptLine $line, array $inputs, User $user, ?int $importBatchId = null, bool $onlyIfAllValid = false, array $damaged = []): array
    {
        $receipt = $line->receipt;
        $this->receipts->assertDraft($receipt);

        if (! $line->is_serialized) {
            return array_map(fn (string $raw): array => ['raw' => $raw, 'serial' => null, 'result' => 'not_serialized', 'message' => __('stock.results.not_serialized')], $inputs);
        }

        $rows = $this->checker->prepare($inputs);
        $valid = array_values(array_filter(array_column($rows, 'serial'), fn (?string $serial): bool => $serial !== null));
        $checks = $this->checker->forReceipt($receipt, $line->item, array_values(array_unique($valid)));

        foreach ($rows as $index => $row) {
            if ($row['result'] === 'ok' && isset($checks[$row['serial']])) {
                $rows[$index] = [...$row, ...$checks[$row['serial']]];
            }
        }

        if ($onlyIfAllValid && collect($rows)->contains(fn (array $row): bool => ! in_array($row['result'], ['ok', 'already_in_document'], true))) {
            return $rows;
        }

        try {
            DB::transaction(function () use ($rows, $line, $receipt, $user, $importBatchId, $damaged): void {
                $position = (int) DocumentLineSerial::query()->where('line_type', GoodsReceiptLine::LINE_TYPE)->where('line_id', $line->id)->max('position');

                foreach ($rows as $row) {
                    if ($row['result'] !== 'ok') {
                        continue;
                    }

                    DocumentLineSerial::query()->create([
                        'document_type' => GoodsReceipt::DOCUMENT_TYPE,
                        'document_id' => $receipt->id,
                        'line_type' => GoodsReceiptLine::LINE_TYPE,
                        'line_id' => $line->id,
                        'item_id' => $line->item_id,
                        'serial_number' => $row['serial'],
                        'serial_raw' => $row['raw'],
                        'status' => DocumentLineSerial::DRAFT,
                        'active_key' => DocumentLineSerial::activeKey(GoodsReceipt::DOCUMENT_TYPE, $receipt->id, $line->item_id, (string) $row['serial']),
                        'position' => ++$position,
                        'import_batch_id' => $importBatchId,
                        'created_by' => $user->id,
                        'created_at' => now(),
                        'is_damaged' => array_key_exists((string) $row['serial'], $damaged),
                        'damage_note' => isset($damaged[(string) $row['serial']]) ? mb_substr(trim((string) $damaged[(string) $row['serial']]), 0, 250) : null,
                    ]);
                }

                app(ManageSerials::class)->syncReceiptLine($line->id);
            });
        } catch (UniqueConstraintViolationException) {
            return array_map(fn (array $row): array => $row['result'] === 'ok' ? [...$row, 'result' => 'already_in_document', 'message' => __('stock.results.retry')] : $row, $rows);
        }

        return $rows;
    }
}
