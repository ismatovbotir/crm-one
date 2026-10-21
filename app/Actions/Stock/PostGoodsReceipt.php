<?php

namespace App\Actions\Stock;

use App\Actions\StockOff\CreateStockOffFromReceipt;
use App\Authorization\PermissionMap;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InternalEvent;
use App\Enums\ReceiptSource;
use App\Enums\SerialSource;
use App\Enums\SerialStatus;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\ItemSerial;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Equipment\ServiceQueue;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialLog;
use App\Services\Telegram\Internal\InternalNotifications;
use App\Support\BusinessSettings;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts a goods receipt (Spec 06 21.4, BR-SN.3/5/10, AC-S1): every serialized line has exactly
 * `received_qty` serial rows; discrepancies need a reason; over-receipt above the tolerance needs
 * the warehouse head. Creates the serials (`available`, or `quarantined` for damaged lines) with
 * `registered` events; customer returns move the sold serials to `returned` and add to the
 * returned quantities. Outbox `goods_receipt.posted` (feed `warehouse`). Immutable afterwards.
 */
class PostGoodsReceipt
{
    public function __construct(protected SerialLog $log, protected OneCOutbox $outbox, protected ManageSerials $serials) {}

    public function handle(GoodsReceipt $receipt, User $user): GoodsReceipt
    {
        try {
            $posted = DB::transaction(fn (): GoodsReceipt => $this->post($receipt, $user));
        } catch (UniqueConstraintViolationException) {
            throw new StockActionException('serial_taken_retry');
        }

        // Spec 03 section 23: company groups hear about receipts and discrepancies (no serials).
        $internal = app(InternalNotifications::class);
        $internal->publish(InternalEvent::GoodsReceiptPosted, $posted);

        if ($posted->lines()->whereNotNull('discrepancy_reason')->exists()) {
            $internal->publish(InternalEvent::ReceiptDiscrepancy, $posted);
        }

        // Spec 09 section 15: queued service tickets waiting for these items surface to the service team.
        app(ServiceQueue::class)->stockArrived($posted);

        return $posted;
    }

    protected function post(GoodsReceipt $receipt, User $user): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

        $from = $receipt->status;

        // Spec 06 26.4: a 1C receipt is posted from `checked`; CRM-created kinds also from `in_progress`.
        if (TransitionGoodsReceipt::ability($receipt, GoodsReceiptStatus::Posted) === null) {
            throw new StockActionException($receipt->isFromOneC() && $from === GoodsReceiptStatus::InProgress ? 'receipt_check_first' : 'receipt_not_open');
        }

        if (! PermissionMap::allows($user, 'stock.receive')) {
            throw new StockActionException('receipt_transition_forbidden', ['to' => GoodsReceiptStatus::Posted->label()]);
        }

        // Serialized quantities are counted, never typed (Spec 06 26.4).
        foreach ($receipt->lines()->where('is_serialized', true)->pluck('id') as $lineId) {
            $this->serials->syncReceiptLine((int) $lineId);
        }

        $lines = $receipt->lines()->with('item')->get();

        if ($lines->isEmpty() || $lines->every(fn (GoodsReceiptLine $line): bool => bccomp((string) $line->received_qty, '0', Quantity::SCALE) === 0)) {
            throw new StockActionException('receipt_empty');
        }

        $this->guard($receipt, $lines, $user);
        $global = BusinessSettings::bool('serials.global_uniqueness');
        $return = $receipt->source_type === ReceiptSource::CustomerReturn;

        foreach ($lines as $line) {
            foreach ($line->serialRows()->get() as $row) {
                $serial = $return ? $this->returnSerial($receipt, $line, $row, $user) : $this->registerSerial($receipt, $line, $row, $user, $global);
                $row->forceFill(['item_serial_id' => $serial->id, 'status' => DocumentLineSerial::POSTED])->save();
            }

            if ($return && $line->return_of_delivery_line_id !== null && bccomp((string) $line->received_qty, '0', Quantity::SCALE) > 0) {
                $deliveryLine = DeliveryLine::query()->lockForUpdate()->find($line->return_of_delivery_line_id);
                $deliveryLine?->forceFill(['returned_qty' => bcadd((string) $deliveryLine->returned_qty, (string) $line->received_qty, Quantity::SCALE)])->save();
                $orderLine = $deliveryLine !== null ? OrderLine::query()->lockForUpdate()->find($deliveryLine->order_line_id) : null;
                $orderLine?->forceFill(['returned_qty' => bcadd((string) $orderLine->returned_qty, (string) $line->received_qty, Quantity::SCALE)])->save();
            }
        }

        $receipt->forceFill(['status' => GoodsReceiptStatus::Posted, 'posted_by' => $user->id, 'posted_at' => now()])->save();
        TransitionGoodsReceipt::record($receipt, $from, GoodsReceiptStatus::Posted, $user);
        // Spec 06 27.2: damaged units open a draft stock-off act linked to this receipt.
        app(CreateStockOffFromReceipt::class)->handle($receipt, $user);
        $this->outbox->publishGoodsReceipt($receipt->fresh());

        return $receipt;
    }

    /**
     * Completeness checks shared by "mark checked" and posting; `$headCheck` adds the warehouse
     * head approval for over-receipt above the tolerance (asked for when posting).
     *
     * @param  Collection<int, GoodsReceiptLine>  $lines
     */
    public function guard(GoodsReceipt $receipt, $lines, User $user, bool $headCheck = true): void
    {
        $incomplete = [];
        $tolerance = BusinessSettings::int('serials.over_receipt_tolerance_bp');

        foreach ($lines as $line) {
            $received = (string) $line->received_qty;

            if ($line->is_serialized) {
                $count = $line->serialRows()->count();

                if (! Quantity::fitsPrecision(Quantity::trimmed($received), 0) || bccomp((string) $count, $received, Quantity::SCALE) !== 0) {
                    $incomplete[] = $line->position.'. '.$line->item?->name.' ('.$count.' / '.Quantity::trimmed($received).')';
                }
            }

            if (bccomp((string) $line->damaged_qty, $received, Quantity::SCALE) > 0) {
                throw new StockActionException('damaged_exceeds', ['line' => $line->position]);
            }

            // Spec 06 26.4: damage is explained in the line note, or (serialized) on every damaged serial.
            if (bccomp((string) $line->damaged_qty, '0', Quantity::SCALE) > 0 && trim((string) $line->note) === ''
                && (! $line->is_serialized || $line->serialRows()->where('is_damaged', true)->where(fn ($query) => $query->whereNull('damage_note')->orWhere('damage_note', ''))->exists())) {
                throw new StockActionException('damaged_note_required', ['line' => $line->position]);
            }

            if ($line->expected_qty !== null && bccomp($received, (string) $line->expected_qty, Quantity::SCALE) !== 0) {
                if ($line->discrepancy_reason === null) {
                    throw new StockActionException('discrepancy_reason_required', ['line' => $line->position]);
                }

                $limit = bcadd((string) $line->expected_qty, bcdiv(bcmul((string) $line->expected_qty, (string) $tolerance, 3), '10000', 3), Quantity::SCALE);

                if ($headCheck && bccomp($received, $limit, Quantity::SCALE) > 0 && ! PermissionMap::allows($user, 'stock.adjust_approve')) {
                    throw new StockActionException('over_receipt_needs_head', ['line' => $line->position]);
                }
            }
        }

        if ($incomplete !== []) {
            throw new StockActionException('serials_incomplete', ['lines' => implode('; ', $incomplete)]);
        }
    }

    protected function registerSerial(GoodsReceipt $receipt, GoodsReceiptLine $line, DocumentLineSerial $row, User $user, bool $global): ItemSerial
    {
        $serial = ItemSerial::query()->create([
            'item_id' => $line->item_id,
            'serial_raw' => $row->serial_raw,
            'serial_normalized' => $row->serial_number,
            'global_key' => $global ? $row->serial_number : null,
            // Only serials flagged damaged are quarantined (Spec 06 26.4).
            'status' => $row->is_damaged ? SerialStatus::Quarantined : SerialStatus::Available,
            'warehouse_id' => $receipt->warehouse_id,
            'source' => in_array($receipt->source_type, [ReceiptSource::OpeningBalance, ReceiptSource::ManualRegistration], true) ? SerialSource::OpeningBalance : SerialSource::Receipt,
            'received_receipt_line_id' => $line->id,
            'received_at' => now(),
        ]);

        $this->log->record($serial, 'registered', null, $user, ['document_type' => GoodsReceipt::DOCUMENT_TYPE, 'document_id' => $receipt->id, 'line_id' => $line->id, 'import_batch_id' => $row->import_batch_id]);

        return $serial;
    }

    protected function returnSerial(GoodsReceipt $receipt, GoodsReceiptLine $line, DocumentLineSerial $row, User $user): ItemSerial
    {
        $serial = ItemSerial::query()->where('item_id', $line->item_id)->where('serial_normalized', $row->serial_number)->lockForUpdate()->first();

        if ($serial === null || $serial->status !== SerialStatus::Sold) {
            throw new StockActionException('return_serial_invalid', ['serial' => $row->serial_number]);
        }

        $from = $serial->status;
        $serial->forceFill(['status' => SerialStatus::Returned, 'warehouse_id' => $receipt->warehouse_id, 'lock_version' => $serial->lock_version + 1])->save();
        $this->log->record($serial, 'returned', $from, $user, ['document_type' => GoodsReceipt::DOCUMENT_TYPE, 'document_id' => $receipt->id, 'line_id' => $line->id, 'customer_id' => $serial->customer_id]);

        return $serial;
    }
}
