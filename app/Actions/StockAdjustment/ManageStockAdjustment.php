<?php

namespace App\Actions\StockAdjustment;

use App\Enums\SerialStatus;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockAdjustmentType;
use App\Models\Delivery;
use App\Models\DocumentLineSerial;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\NumberSequence;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Stock\Exceptions\StockAdjustmentException;
use App\Services\Stock\SerialNormalizer;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustment drafts (Spec 06 section 21.4): create, and add or remove lines per type:
 * - `found`: a serial number of a serial-tracked item that is not in the registry yet (or a
 *   written-off one coming back);
 * - `serial_correction`: a serial of a posted delivery note replaced by another available serial of
 *   the same item and warehouse; the old unit goes back to `available` or to `quarantined`;
 * - `qty_correction`: a signed quantity for a non-serialized item.
 * Serials may sit on one open adjustment only.
 */
class ManageStockAdjustment
{
    public const OUTCOMES = ['available', 'quarantined'];

    public function __construct(protected SerialNormalizer $normalizer) {}

    /**
     * @param  array{type: string, warehouse_id?: int|null, delivery_id?: int|null, reason: string}  $data
     */
    public function create(array $data, User $user): StockAdjustment
    {
        $type = StockAdjustmentType::tryFrom($data['type']) ?? throw new StockAdjustmentException('type_required');
        $reason = trim((string) $data['reason']);

        if ($reason === '') {
            throw new StockAdjustmentException('reason_required');
        }

        $delivery = null;

        if ($type === StockAdjustmentType::SerialCorrection) {
            $delivery = Delivery::query()->find((int) ($data['delivery_id'] ?? 0)) ?? throw new StockAdjustmentException('delivery_required');

            if ($delivery->status->value !== 'posted') {
                throw new StockAdjustmentException('delivery_not_posted', ['number' => $delivery->number]);
            }
        }

        $warehouseId = $delivery?->warehouse_id ?? (int) ($data['warehouse_id'] ?? 0);
        $warehouse = Warehouse::query()->find($warehouseId) ?? throw new StockAdjustmentException('warehouse_required');

        return DB::transaction(function () use ($type, $reason, $delivery, $warehouse, $user): StockAdjustment {
            $year = (int) AccountingPeriods::today()->year;
            $adjustment = StockAdjustment::query()->create([
                'number' => NumberSequence::format('ADJ', $year, NumberSequence::next('ADJ', $year)),
                'type' => $type,
                'status' => StockAdjustmentStatus::Draft,
                'warehouse_id' => $warehouse->id,
                'delivery_id' => $delivery?->id,
                'reason' => mb_substr($reason, 0, 500),
                'created_by' => $user->id,
            ]);
            TransitionStockAdjustment::record($adjustment, null, StockAdjustmentStatus::Draft, $user);

            return $adjustment;
        });
    }

    public function updateReason(StockAdjustment $adjustment, string $reason): StockAdjustment
    {
        $this->assertDraft($adjustment);

        if (trim($reason) === '') {
            throw new StockAdjustmentException('reason_required');
        }

        $adjustment->forceFill(['reason' => mb_substr(trim($reason), 0, 500)])->save();

        return $adjustment;
    }

    /** `found`: one serial number of a serial-tracked item. */
    public function addFoundSerial(StockAdjustment $adjustment, Item $item, string $input): StockAdjustmentLine
    {
        $this->assertDraft($adjustment, StockAdjustmentType::Found);

        if (! $item->is_serialized) {
            throw new StockAdjustmentException('item_not_serialized', ['item' => $item->name]);
        }

        $serial = $this->normalize($input);

        return DB::transaction(function () use ($adjustment, $item, $serial): StockAdjustmentLine {
            $existing = ItemSerial::query()->where('item_id', $item->id)->where('serial_normalized', $serial['value'])->lockForUpdate()->first();

            if ($existing !== null && $existing->status !== SerialStatus::WrittenOff) {
                throw new StockAdjustmentException('serial_registered', ['serial' => $serial['value'], 'status' => $existing->status->label()]);
            }

            if ($existing === null && BusinessSettings::bool('serials.global_uniqueness') && ItemSerial::query()->where('global_key', $serial['value'])->exists()) {
                throw new StockAdjustmentException('serial_registered_other_item', ['serial' => $serial['value']]);
            }

            $this->assertNotOnOpenAdjustment($serial['value'], $item->id);

            return $adjustment->lines()->create([
                'position' => (int) $adjustment->lines()->max('position') + 1,
                'item_id' => $item->id,
                'quantity' => '1',
                'serial_raw' => $serial['raw'],
                'serial_normalized' => $serial['value'],
                'new_item_serial_id' => $existing?->id,
            ]);
        });
    }

    /** `serial_correction`: replace a serial of the posted delivery note by another one. */
    public function addCorrection(StockAdjustment $adjustment, DocumentLineSerial $row, string $newInput, string $outcome): StockAdjustmentLine
    {
        $this->assertDraft($adjustment, StockAdjustmentType::SerialCorrection);

        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new StockAdjustmentException('outcome_required');
        }

        if ($row->document_type !== Delivery::DOCUMENT_TYPE || $row->document_id !== $adjustment->delivery_id || $row->status !== DocumentLineSerial::POSTED) {
            throw new StockAdjustmentException('row_not_on_delivery');
        }

        $new = $this->normalize($newInput);

        return DB::transaction(function () use ($adjustment, $row, $new, $outcome): StockAdjustmentLine {
            $old = ItemSerial::query()->lockForUpdate()->findOrFail($row->item_serial_id);
            $replacement = ItemSerial::query()->where('item_id', $old->item_id)->where('serial_normalized', $new['value'])->lockForUpdate()->first();

            if ($replacement === null) {
                throw new StockAdjustmentException('replacement_not_found', ['serial' => $new['value']]);
            }

            if ($old->status !== SerialStatus::Sold) {
                throw new StockAdjustmentException('old_not_sold', ['serial' => $old->serial_normalized, 'status' => $old->status->label()]);
            }

            if ($replacement->status !== SerialStatus::Available || $replacement->warehouse_id !== $adjustment->warehouse_id) {
                throw new StockAdjustmentException('replacement_unavailable', ['serial' => $replacement->serial_normalized, 'status' => $replacement->status->label()]);
            }

            if ($adjustment->lines()->where('old_item_serial_id', $old->id)->exists()) {
                throw new StockAdjustmentException('serial_already_added', ['serial' => $old->serial_normalized]);
            }

            $this->assertNotOnOpenAdjustment($replacement->serial_normalized, $old->item_id);
            $this->assertNotOnOpenAdjustment($old->serial_normalized, $old->item_id);

            return $adjustment->lines()->create([
                'position' => (int) $adjustment->lines()->max('position') + 1,
                'item_id' => $old->item_id,
                'quantity' => '1',
                'serial_raw' => $replacement->serial_raw,
                'serial_normalized' => $replacement->serial_normalized,
                'old_item_serial_id' => $old->id,
                'new_item_serial_id' => $replacement->id,
                'delivery_line_id' => $row->line_id,
                'old_serial_outcome' => $outcome,
            ]);
        });
    }

    /** `qty_correction`: a signed quantity for a non-serialized item (negative lowers the stock). */
    public function addQuantity(StockAdjustment $adjustment, Item $item, string $quantity, ?string $note = null): StockAdjustmentLine
    {
        $this->assertDraft($adjustment, StockAdjustmentType::QtyCorrection);

        if ($item->is_serialized) {
            throw new StockAdjustmentException('item_serialized', ['item' => $item->name]);
        }

        $normalized = Quantity::normalize($quantity);
        $negative = str_starts_with($normalized, '-');
        $absolute = ltrim($normalized, '-');

        if (! Quantity::isDecimal($absolute) || Quantity::decimals($absolute) > Quantity::SCALE || bccomp($absolute, '0', Quantity::SCALE) === 0 || bccomp($absolute, '999999999', Quantity::SCALE) > 0) {
            throw new StockAdjustmentException('quantity_invalid');
        }

        if ($adjustment->lines()->where('item_id', $item->id)->exists()) {
            throw new StockAdjustmentException('item_already_added', ['item' => $item->name]);
        }

        return $adjustment->lines()->create([
            'position' => (int) $adjustment->lines()->max('position') + 1,
            'item_id' => $item->id,
            'quantity' => ($negative ? '-' : '').$absolute,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null,
        ]);
    }

    public function removeLine(StockAdjustmentLine $line): void
    {
        $this->assertDraft($line->adjustment);
        $line->delete();
    }

    /**
     * @return array{value: string, raw: string}
     */
    protected function normalize(string $input): array
    {
        $result = $this->normalizer->normalize($input);

        if ($result['error'] !== null) {
            throw new StockAdjustmentException('serial_invalid', ['detail' => __('stock.results.'.$result['error'])]);
        }

        return ['value' => (string) $result['value'], 'raw' => $result['raw']];
    }

    protected function assertDraft(StockAdjustment $adjustment, ?StockAdjustmentType $type = null): void
    {
        if (! $adjustment->status->isEditable()) {
            throw new StockAdjustmentException('not_editable');
        }

        if ($type !== null && $adjustment->type !== $type) {
            throw new StockAdjustmentException('wrong_type', ['type' => $adjustment->type->label()]);
        }
    }

    /** A serial sits on one open adjustment at a time. */
    protected function assertNotOnOpenAdjustment(string $serial, int $itemId): void
    {
        $other = StockAdjustmentLine::query()
            ->where('item_id', $itemId)
            ->where(fn ($query) => $query->where('serial_normalized', $serial)->orWhereHas('oldSerial', fn ($q) => $q->where('serial_normalized', $serial)))
            ->whereHas('adjustment', fn ($query) => $query->whereIn('status', array_map(fn (StockAdjustmentStatus $status): string => $status->value, array_filter(StockAdjustmentStatus::cases(), fn (StockAdjustmentStatus $status): bool => $status->isOpen()))))
            ->with('adjustment')
            ->first();

        if ($other !== null) {
            throw new StockAdjustmentException('serial_on_open_adjustment', ['serial' => $serial, 'number' => $other->adjustment->number]);
        }
    }
}
