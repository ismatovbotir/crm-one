<?php

namespace App\Actions\Stock;

use App\Enums\GoodsReceiptStatus;
use App\Enums\SerialStatus;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceiptLine;
use App\Models\ItemSerial;
use App\Models\User;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialLog;
use Illuminate\Support\Facades\DB;

/**
 * Serial row edits on drafts and inspections (Spec 06 BR-SN.8, 21.4): remove a draft row (a
 * claimed delivery serial goes back to `available` with a `released` event) and inspect returned/quarantined units back to `available`.
 */
class ManageSerials
{
    public function __construct(protected SerialLog $log) {}

    public function removeRow(DocumentLineSerial $row, User $user): void
    {
        DB::transaction(function () use ($row, $user): void {
            $row = DocumentLineSerial::query()->lockForUpdate()->findOrFail($row->id);

            if ($row->status !== DocumentLineSerial::DRAFT) {
                throw new StockActionException('row_locked');
            }

            if ($row->line_type === GoodsReceiptLine::LINE_TYPE && GoodsReceiptLine::query()->whereKey($row->line_id)->whereHas('receipt', fn ($query) => $query->where('status', '!=', GoodsReceiptStatus::InProgress))->exists()) {
                throw new StockActionException('receipt_not_editable');
            }

            if ($row->line_type === DeliveryLine::LINE_TYPE && DeliveryLine::query()->whereKey($row->line_id)->whereHas('delivery', fn ($query) => $query->whereNotNull('packed_at'))->exists()) {
                throw new StockActionException('packing_locked');
            }

            if ($row->line_type === DeliveryLine::LINE_TYPE && $row->item_serial_id !== null) {
                $this->release(ItemSerial::query()->lockForUpdate()->findOrFail($row->item_serial_id), $user, 'removed_from_draft', $row);
                $row->forceFill(['status' => DocumentLineSerial::VOIDED, 'active_key' => null])->save();
                $this->syncDeliveryLineQuantity($row->line_id);

                return;
            }

            $row->delete();

            if ($row->line_type === GoodsReceiptLine::LINE_TYPE) {
                $this->syncReceiptLine($row->line_id);
            }
        });
    }

    public function release(ItemSerial $serial, ?User $user, string $reason, ?DocumentLineSerial $row = null): void
    {
        if ($serial->status !== SerialStatus::Reserved) {
            return;
        }

        $serial->forceFill(['status' => SerialStatus::Available, 'reserved_by_line_type' => null, 'reserved_by_line_id' => null, 'reserved_at' => null, 'lock_version' => $serial->lock_version + 1])->save();
        $this->log->record($serial, 'released', SerialStatus::Reserved, $user, ['document_type' => $row?->document_type, 'document_id' => $row?->document_id, 'line_id' => $row?->line_id, 'reason' => $reason]);
    }

    public function syncDeliveryLineQuantity(int $lineId): void
    {
        $line = DeliveryLine::query()->find($lineId);

        if ($line !== null && $line->is_serialized) {
            $line->forceFill(['quantity' => (string) $line->serialRows()->count()])->save();
        }
    }

    /**
     * Spec 06 26.4: a serialized receipt line's received quantity is the number of its serial
     * rows and its damaged quantity the number flagged damaged; nobody types them by hand.
     */
    public function syncReceiptLine(int $lineId): void
    {
        $line = GoodsReceiptLine::query()->find($lineId);

        if ($line === null || ! $line->is_serialized) {
            return;
        }

        $rows = $line->serialRows()->get(['id', 'is_damaged']);
        $received = $rows->count();
        $damaged = $rows->where('is_damaged', true)->count();
        $line->forceFill([
            'received_qty' => (string) $received,
            'damaged_qty' => (string) $damaged,
            'condition' => $damaged === 0 ? 'good' : ($damaged === $received ? 'damaged' : 'mixed'),
        ])->save();
    }

    /** Flags or clears damage on one draft serial row of an editable receipt (Spec 06 26.4). */
    public function markDamaged(DocumentLineSerial $row, bool $damaged, ?string $note, User $user): DocumentLineSerial
    {
        return DB::transaction(function () use ($row, $damaged, $note): DocumentLineSerial {
            $row = DocumentLineSerial::query()->lockForUpdate()->findOrFail($row->id);
            $line = GoodsReceiptLine::query()->with('receipt')->find($row->line_id);

            if ($row->line_type !== GoodsReceiptLine::LINE_TYPE || $line === null || $row->status !== DocumentLineSerial::DRAFT) {
                throw new StockActionException('row_locked');
            }

            if (! $line->receipt->status->isEditable()) {
                throw new StockActionException('receipt_not_editable');
            }

            $note = $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 250) : null;
            $row->forceFill(['is_damaged' => $damaged, 'damage_note' => $damaged ? $note : null])->save();
            $this->syncReceiptLine($line->id);

            return $row;
        });
    }

    /** returned / quarantined → available after inspection (21.4). */
    public function inspect(ItemSerial $serial, User $user, ?string $note = null): ItemSerial
    {
        if (! in_array($serial->status, [SerialStatus::Returned, SerialStatus::Quarantined], true)) {
            throw new StockActionException('not_under_inspection');
        }

        $from = $serial->status;
        $serial->forceFill(['status' => SerialStatus::Available, 'lock_version' => $serial->lock_version + 1])->save();
        $this->log->record($serial, 'inspected', $from, $user, ['reason' => $note]);

        return $serial;
    }
}
