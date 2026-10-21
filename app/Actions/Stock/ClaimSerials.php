<?php

namespace App\Actions\Stock;

use App\Enums\SerialStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\ItemSerial;
use App\Models\User;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialChecker;
use App\Services\Stock\SerialLog;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Registers serials on a draft delivery note line (Spec 06 BR-SN.6/7, AC-S4/S5): inputs are
 * normalized and checked; valid serials are claimed atomically — candidate rows locked in id
 * order, conditional `available → reserved` update whose affected count must match, serial rows
 * inserted (unique `active_key` as the last guard), events written. A concurrent claim of the same
 * serial loses cleanly with "Reserved by DN-…". The line quantity follows the serial count and
 * never exceeds the open quantity.
 */
class ClaimSerials
{
    public function __construct(
        protected SerialChecker $checker,
        protected SerialLog $log,
        protected ManageDeliveryNote $notes,
        protected ManageSerials $serials,
    ) {}

    /**
     * @param  list<string>  $inputs
     * @return list<array{raw: string, serial: string|null, result: string, message: string}>
     */
    public function handle(DeliveryLine $line, array $inputs, User $user, ?int $importBatchId = null, bool $onlyIfAllValid = false): array
    {
        $delivery = $line->delivery;
        $this->notes->assertEditable($delivery);

        if (! $line->is_serialized) {
            throw new StockActionException('line_not_serialized');
        }

        $rows = $this->checker->prepare($inputs);
        $candidates = array_values(array_unique(array_filter(array_column($rows, 'serial'))));
        $checks = $this->checker->forDelivery($delivery, $line, $candidates, $user);

        foreach ($rows as $index => $row) {
            if ($row['result'] === 'ok' && isset($checks[$row['serial']])) {
                $rows[$index] = [...$row, ...$checks[$row['serial']]];
            }
        }

        $valid = array_values(array_filter($rows, fn (array $row): bool => $row['result'] === 'ok'));

        if ($valid === [] || ($onlyIfAllValid && count($valid) !== count(array_filter($rows, fn (array $row): bool => $row['result'] !== 'already_in_document')))) {
            return $rows;
        }

        $open = bcsub($this->notes->openQuantity($line->orderLine, $line->id), (string) $line->quantity, Quantity::SCALE);

        if (bccomp((string) count($valid), $open, Quantity::SCALE) > 0) {
            throw new StockActionException('exceeds_open', ['qty' => Quantity::trimmed($open)]);
        }

        try {
            DB::transaction(function () use ($valid, $line, $delivery, $user, $importBatchId): void {
                $serials = ItemSerial::query()->where('item_id', $line->item_id)->whereIn('serial_normalized', array_column($valid, 'serial'))->orderBy('id')->lockForUpdate()->get();

                $affected = ItemSerial::query()
                    ->whereKey($serials->modelKeys())
                    ->where('status', SerialStatus::Available)
                    ->where('warehouse_id', $delivery->warehouse_id)
                    ->update([
                        'status' => SerialStatus::Reserved,
                        'reserved_by_line_type' => DeliveryLine::LINE_TYPE,
                        'reserved_by_line_id' => $line->id,
                        'reserved_at' => now(),
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_at' => now(),
                    ]);

                if ($affected !== count($valid)) {
                    throw new StockActionException('claim_conflict');
                }

                $position = (int) DocumentLineSerial::query()->where('line_type', DeliveryLine::LINE_TYPE)->where('line_id', $line->id)->max('position');

                foreach ($serials as $serial) {
                    $raw = collect($valid)->firstWhere('serial', $serial->serial_normalized)['raw'] ?? $serial->serial_normalized;

                    DocumentLineSerial::query()->create([
                        'document_type' => Delivery::DOCUMENT_TYPE,
                        'document_id' => $delivery->id,
                        'line_type' => DeliveryLine::LINE_TYPE,
                        'line_id' => $line->id,
                        'item_id' => $line->item_id,
                        'item_serial_id' => $serial->id,
                        'serial_number' => $serial->serial_normalized,
                        'serial_raw' => $raw,
                        'status' => DocumentLineSerial::DRAFT,
                        'active_key' => DocumentLineSerial::activeKey(Delivery::DOCUMENT_TYPE, $delivery->id, $line->item_id, $serial->serial_normalized),
                        'position' => ++$position,
                        'import_batch_id' => $importBatchId,
                        'created_by' => $user->id,
                        'created_at' => now(),
                    ]);

                    $this->log->record($serial->fresh(), 'reserved', SerialStatus::Available, $user, ['document_type' => Delivery::DOCUMENT_TYPE, 'document_id' => $delivery->id, 'line_id' => $line->id, 'import_batch_id' => $importBatchId]);
                }

                $this->serials->syncDeliveryLineQuantity($line->id);
            });
        } catch (StockActionException|UniqueConstraintViolationException) {
            // Someone else claimed one of them in the meantime: re-check to report who.
            $recheck = $this->checker->forDelivery($delivery, $line, array_column($valid, 'serial'), $user);

            return array_map(fn (array $row): array => $row['result'] === 'ok' && isset($recheck[$row['serial']]) && $recheck[$row['serial']]['result'] !== 'ok'
                ? [...$row, ...$recheck[$row['serial']]]
                : ($row['result'] === 'ok' ? [...$row, 'result' => 'retry', 'message' => __('stock.results.retry')] : $row), $rows);
        }

        return $rows;
    }
}
