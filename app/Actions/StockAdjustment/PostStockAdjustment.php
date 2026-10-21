<?php

namespace App\Actions\StockAdjustment;

use App\Enums\SerialSource;
use App\Enums\SerialStatus;
use App\Enums\StockAdjustmentType;
use App\Models\CustomerAsset;
use App\Models\Delivery;
use App\Models\DocumentLineSerial;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\User;
use App\Services\Equipment\AssetRegister;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Stock\Exceptions\StockAdjustmentException;
use App\Services\Stock\SerialLog;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Applies an approved stock adjustment (Spec 06 section 21.4), inside the approval transaction:
 * - `found`: the serial is registered `available` at the warehouse (a written-off row is revived),
 *   event `found`, the CRM stock mirror rises by one;
 * - `serial_correction`: on the posted delivery note the old serial goes back to `available` or to
 *   `quarantined`, the replacement is sold to the same customer (warranty carried over), the old
 *   serial row is `replaced` and a new `posted` row takes its place, the customer's equipment entry
 *   follows the new serial; events `corrected`;
 * - `qty_correction`: the CRM stock mirror moves by the signed quantity.
 * The stock mirror does not move on a serial correction (one unit back, one out). 1C stays the quantity truth and overwrites the mirror on its next push; the outbox event
 * `stock_adjustment.posted` is available for 1C to pull.
 */
class PostStockAdjustment
{
    public function __construct(protected SerialLog $log, protected OneCOutbox $outbox) {}

    public function handle(StockAdjustment $adjustment, User $user): void
    {
        $lines = $adjustment->lines()->get();

        if ($lines->isEmpty()) {
            throw new StockAdjustmentException('lines_incomplete');
        }

        foreach ($lines as $line) {
            match ($adjustment->type) {
                StockAdjustmentType::Found => $this->found($adjustment, $line, $user),
                StockAdjustmentType::SerialCorrection => $this->correct($adjustment, $line, $user),
                StockAdjustmentType::QtyCorrection => $this->quantity($adjustment, $line),
            };
        }
    }

    public function publish(StockAdjustment $adjustment): void
    {
        $this->outbox->publishStockAdjustment($adjustment->fresh());
    }

    protected function found(StockAdjustment $adjustment, StockAdjustmentLine $line, User $user): void
    {
        $revived = $line->new_item_serial_id !== null ? ItemSerial::query()->lockForUpdate()->findOrFail($line->new_item_serial_id) : null;

        if ($revived !== null) {
            if ($revived->status !== SerialStatus::WrittenOff) {
                throw new StockAdjustmentException('serial_registered', ['serial' => $revived->serial_normalized, 'status' => $revived->status->label()]);
            }

            $from = $revived->status;
            $revived->forceFill(['status' => SerialStatus::Available, 'warehouse_id' => $adjustment->warehouse_id, 'lock_version' => $revived->lock_version + 1])->save();
            $serial = $revived;
        } else {
            $this->assertFree($line);
            $from = null;
            $serial = new ItemSerial([
                'item_id' => $line->item_id,
                'serial_raw' => $line->serial_raw,
                'serial_normalized' => $line->serial_normalized,
                'global_key' => BusinessSettings::bool('serials.global_uniqueness') ? $line->serial_normalized : null,
                'status' => SerialStatus::Available,
                'warehouse_id' => $adjustment->warehouse_id,
                'source' => SerialSource::Found,
                'received_at' => now(),
            ]);
            $serial->forceFill(['lock_version' => 0])->save();
        }

        $this->log->record($serial, 'found', $from, $user, [
            'document_type' => StockAdjustment::DOCUMENT_TYPE, 'document_id' => $adjustment->id, 'line_id' => $line->id, 'reason' => $adjustment->reason,
        ]);
        $this->moveStock($line->item_id, $adjustment->warehouse_id, '1');
    }

    protected function assertFree(StockAdjustmentLine $line): void
    {
        $existing = ItemSerial::query()->where('item_id', $line->item_id)->where('serial_normalized', $line->serial_normalized)->first();

        if ($existing !== null) {
            throw new StockAdjustmentException('serial_registered', ['serial' => $existing->serial_normalized, 'status' => $existing->status->label()]);
        }
    }

    protected function correct(StockAdjustment $adjustment, StockAdjustmentLine $line, User $user): void
    {
        $ids = [$line->old_item_serial_id, $line->new_item_serial_id];
        $serials = ItemSerial::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $old = $serials->get($line->old_item_serial_id) ?? throw new StockAdjustmentException('row_not_on_delivery');
        $new = $serials->get($line->new_item_serial_id) ?? throw new StockAdjustmentException('replacement_not_found', ['serial' => (string) $line->serial_normalized]);
        $row = DocumentLineSerial::query()->where('document_type', Delivery::DOCUMENT_TYPE)->where('document_id', $adjustment->delivery_id)
            ->where('item_serial_id', $old->id)->where('status', DocumentLineSerial::POSTED)->lockForUpdate()->first() ?? throw new StockAdjustmentException('row_not_on_delivery');

        if ($old->status !== SerialStatus::Sold) {
            throw new StockAdjustmentException('old_not_sold', ['serial' => $old->serial_normalized, 'status' => $old->status->label()]);
        }

        if ($new->status !== SerialStatus::Available || $new->warehouse_id !== $adjustment->warehouse_id) {
            throw new StockAdjustmentException('replacement_unavailable', ['serial' => $new->serial_normalized, 'status' => $new->status->label()]);
        }

        $sale = [
            'sold_delivery_line_id' => $old->sold_delivery_line_id,
            'sold_order_line_id' => $old->sold_order_line_id,
            'customer_id' => $old->customer_id,
            'sold_at' => $old->sold_at,
            'warranty_until' => $old->warranty_until,
        ];
        $context = ['document_type' => StockAdjustment::DOCUMENT_TYPE, 'document_id' => $adjustment->id, 'line_id' => $line->id, 'reason' => $adjustment->reason];

        $outcome = $line->old_serial_outcome === 'quarantined' ? SerialStatus::Quarantined : SerialStatus::Available;
        $oldFrom = $old->status;
        $old->forceFill([
            'status' => $outcome, 'warehouse_id' => $adjustment->warehouse_id, 'sold_delivery_line_id' => null, 'sold_order_line_id' => null,
            'customer_id' => null, 'sold_at' => null, 'warranty_until' => null, 'lock_version' => $old->lock_version + 1,
        ])->save();
        $this->log->record($old, 'corrected', $oldFrom, $user, [...$context, 'warehouse_from_id' => null]);

        $newFrom = $new->status;
        $new->forceFill([...$sale, 'status' => SerialStatus::Sold, 'warehouse_id' => null, 'lock_version' => $new->lock_version + 1])->save();
        $this->log->record($new, 'corrected', $newFrom, $user, [...$context, 'customer_id' => $old->customer_id, 'warehouse_from_id' => $adjustment->warehouse_id]);

        $replacement = $row->replicate(['id', 'active_key', 'status', 'replaced_by_id']);
        $replacement->forceFill([
            'item_serial_id' => $new->id, 'serial_number' => $new->serial_normalized, 'serial_raw' => $new->serial_raw, 'status' => DocumentLineSerial::POSTED,
            'active_key' => DocumentLineSerial::activeKey(Delivery::DOCUMENT_TYPE, (int) $adjustment->delivery_id, $new->item_id, $new->serial_normalized),
            'created_by' => $user->id, 'created_at' => now(),
        ]);
        $row->forceFill(['status' => DocumentLineSerial::REPLACED, 'active_key' => null])->save();
        $replacement->save();
        $row->forceFill(['replaced_by_id' => $replacement->id])->save();

        $asset = CustomerAsset::query()->where('item_serial_id', $old->id)->first();
        $asset?->forceFill([
            'item_serial_id' => $new->id, 'serial_raw' => $new->serial_raw, 'serial_normalized' => $new->serial_normalized,
            'warranty_until' => AssetRegister::warrantyFor($new->loadMissing('item'))?->toDateString(),
        ])->save();
    }

    protected function quantity(StockAdjustment $adjustment, StockAdjustmentLine $line): void
    {
        $this->moveStock($line->item_id, $adjustment->warehouse_id, (string) $line->quantity, strict: true);
    }

    /**
     * Moves the CRM stock mirror; a deficit is refused only for quantity corrections (strict).
     */
    protected function moveStock(int $itemId, int $warehouseId, string $delta, bool $strict = false): void
    {
        if (bccomp($delta, '0', Quantity::SCALE) === 0) {
            return;
        }

        $stock = ItemStock::query()->where('item_id', $itemId)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
        $current = $stock !== null ? (string) $stock->quantity : '0';
        $next = bcadd($current, $delta, Quantity::SCALE);

        if (bccomp($next, '0', Quantity::SCALE) < 0) {
            if ($strict) {
                throw new StockAdjustmentException('stock_below_zero', ['available' => Quantity::trimmed($current)]);
            }

            $next = '0';
        }

        if ($stock === null) {
            DB::table('item_stocks')->insert(['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'quantity' => $next, 'reserved' => '0', 'as_of' => now(), 'received_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return;
        }

        $stock->forceFill(['quantity' => $next])->save();
    }
}
