<?php

namespace App\Actions\StockOff;

use App\Enums\StockOffReason;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\StockOffAct;
use App\Models\StockOffLineSerial;
use App\Models\User;
use App\Support\Quantity;

/**
 * Spec 06 27.2: posting a goods receipt with damaged units opens a DRAFT stock-off act
 * (reason "damaged at receipt") linked to the receipt, its lines and the flagged serials (now
 * quarantined), with the supplier for a claim. The stock manager completes it with evidence and
 * submits it, or cancels it when inspection releases the units.
 */
class CreateStockOffFromReceipt
{
    public function __construct(protected ManageStockOffAct $manage) {}

    public function handle(GoodsReceipt $receipt, User $user): ?StockOffAct
    {
        $lines = $receipt->lines()->with('item')->get()->filter(fn ($line): bool => bccomp((string) $line->damaged_qty, '0', Quantity::SCALE) > 0);

        if ($lines->isEmpty() || StockOffAct::query()->where('goods_receipt_id', $receipt->id)->exists()) {
            return null;
        }

        $act = $this->manage->create([
            'warehouse_id' => $receipt->warehouse_id,
            'reason' => StockOffReason::DamagedAtReceipt->value,
            'note' => __('stock_off.from_receipt_note', ['number' => $receipt->number]),
            'goods_receipt_id' => $receipt->id,
            'supplier_partner_id' => $receipt->supplier_partner_id,
        ], $user);

        foreach ($lines as $receiptLine) {
            $line = $this->manage->addLine($act, $receiptLine->item ?? Item::query()->findOrFail($receiptLine->item_id), (string) $receiptLine->damaged_qty, $receiptLine->note, $receiptLine->id);

            if (! $line->is_serialized) {
                continue;
            }

            $rows = DocumentLineSerial::query()->where('line_type', $receiptLine::LINE_TYPE)->where('line_id', $receiptLine->id)
                ->where('status', DocumentLineSerial::POSTED)->where('is_damaged', true)->whereNotNull('item_serial_id')->get();

            foreach ($rows as $row) {
                StockOffLineSerial::query()->create([
                    'line_id' => $line->id,
                    'item_serial_id' => $row->item_serial_id,
                    'serial_number' => $row->serial_number,
                    'active_key' => StockOffLineSerial::activeKey((int) $row->item_serial_id),
                    'created_at' => now(),
                ]);
            }

            $line->forceFill(['quantity' => Quantity::toStorage((string) $rows->count())])->save();
        }

        $this->manage->revalue($act);
        $receipt->logActivity('stock_off_opened', ['act' => $act->number]);

        return $act;
    }
}
