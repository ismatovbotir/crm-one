<?php

namespace App\Actions\Stock;

use App\Enums\DiscrepancyReason;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ReceiptSource;
use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Stock\Exceptions\StockActionException;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Goods receipt work (Spec 06 21.4, 26.4): create the CRM kinds (customer return of a posted
 * delivery note, opening balance, serial registration), add/update/remove lines. Supplier
 * receipts come from 1C; their lines are 1C's and only the received side is entered here.
 */
class ManageGoodsReceipt
{
    /** Receipt kinds the CRM may create itself (everything else is pushed by 1C). */
    public const CRM_KINDS = [ReceiptSource::CustomerReturn, ReceiptSource::OpeningBalance, ReceiptSource::ManualRegistration];

    /**
     * @param  array{source_type: string, warehouse_id: int, received_on?: string|null, counterparty_name?: string|null, supplier_doc_ref?: string|null, return_of_delivery_id?: int|null, note?: string|null}  $data
     */
    public function create(array $data, User $user): GoodsReceipt
    {
        $source = ReceiptSource::from($data['source_type']);

        // Spec 06 section 26: supplier receipts come from 1C (POST /goods-receipts); the CRM
        // creates only customer returns, opening balances and serial registrations.
        if (! in_array($source, self::CRM_KINDS, true)) {
            throw new StockActionException('receipt_from_1c_only');
        }
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $delivery = null;

        if ($source === ReceiptSource::CustomerReturn) {
            $delivery = Delivery::query()->with('order')->where('status', StockDocumentStatus::Posted)->find($data['return_of_delivery_id'] ?? 0);

            if ($delivery === null) {
                throw new StockActionException('return_needs_delivery');
            }
        }

        return DB::transaction(function () use ($data, $user, $source, $warehouse, $delivery): GoodsReceipt {
            $year = (int) AccountingPeriods::today()->year;
            $receipt = GoodsReceipt::query()->create([
                'number' => NumberSequence::format('GR', $year, NumberSequence::next('GR', $year)),
                'status' => GoodsReceiptStatus::InProgress,
                'source_type' => $source,
                'counterparty_name' => $data['counterparty_name'] ?? null,
                'customer_id' => $delivery?->order?->customer_id,
                'return_of_delivery_id' => $delivery?->id,
                'warehouse_id' => $warehouse->id,
                'received_on' => $data['received_on'] ?? AccountingPeriods::today()->toDateString(),
                'supplier_doc_ref' => $data['supplier_doc_ref'] ?? null,
                'created_by' => $user->id,
                'note' => $data['note'] ?? null,
            ]);

            TransitionGoodsReceipt::record($receipt, null, GoodsReceiptStatus::InProgress, $user);

            // A return lists the delivered lines; quantities are entered by the stock manager.
            foreach ($delivery?->lines ?? [] as $index => $line) {
                $receipt->lines()->create([
                    'position' => $index + 1,
                    'item_id' => $line->item_id,
                    'is_serialized' => $line->is_serialized,
                    'return_of_delivery_line_id' => $line->id,
                    'expected_qty' => null,
                    'received_qty' => '0',
                ]);
            }

            return $receipt;
        });
    }

    public function addLine(GoodsReceipt $receipt, Item $item, ?string $expectedQty, string $receivedQty): GoodsReceiptLine
    {
        $this->assertDraft($receipt);
        $this->assertCrmLines($receipt);
        // Spec 06 26.4: a serialized line's received quantity follows its serial numbers.
        $received = $item->is_serialized ? '0' : $this->quantity($receivedQty, false);
        $expected = $expectedQty === null || trim($expectedQty) === '' ? null : $this->quantity($expectedQty, false);

        return $receipt->lines()->create([
            'position' => (int) $receipt->lines()->max('position') + 1,
            'item_id' => $item->id,
            'is_serialized' => (bool) $item->is_serialized,
            'expected_qty' => $expected,
            'received_qty' => $received,
        ]);
    }

    /**
     * @param  array{expected_qty?: string|null, received_qty?: string, damaged_qty?: string, condition?: string, discrepancy_reason?: string|null, note?: string|null}  $data
     */
    public function updateLine(GoodsReceiptLine $line, array $data): GoodsReceiptLine
    {
        $this->assertDraft($line->receipt);

        // Expected lines and quantities of a 1C receipt are 1C's; the stock manager enters what arrived.
        if ($line->receipt->isFromOneC()) {
            unset($data['expected_qty']);
        }

        // Received and damaged quantities of a serialized line are counted from its serial rows.
        if ($line->is_serialized) {
            unset($data['received_qty'], $data['damaged_qty'], $data['condition']);
        }
        $attributes = [];

        if (array_key_exists('received_qty', $data)) {
            $attributes['received_qty'] = $this->quantity((string) $data['received_qty'], false);
        }

        if (array_key_exists('expected_qty', $data)) {
            $attributes['expected_qty'] = $data['expected_qty'] === null || trim((string) $data['expected_qty']) === '' ? null : $this->quantity((string) $data['expected_qty'], false);
        }

        if (array_key_exists('damaged_qty', $data)) {
            $attributes['damaged_qty'] = $this->quantity((string) $data['damaged_qty'], false);
        }

        if (array_key_exists('condition', $data) && in_array($data['condition'], ['good', 'damaged', 'mixed'], true)) {
            $attributes['condition'] = $data['condition'];
        }

        // The condition follows the damaged quantity: none = good, all = damaged, some = mixed.
        if (array_key_exists('damaged_qty', $attributes)) {
            $received = $attributes['received_qty'] ?? (string) $line->received_qty;

            if (bccomp($attributes['damaged_qty'], $received, Quantity::SCALE) > 0) {
                throw new StockActionException('damaged_exceeds', ['line' => $line->position]);
            }

            $attributes['condition'] = match (true) {
                bccomp($attributes['damaged_qty'], '0', Quantity::SCALE) === 0 => 'good',
                bccomp($attributes['damaged_qty'], $received, Quantity::SCALE) === 0 => 'damaged',
                default => 'mixed',
            };
        }

        if (array_key_exists('discrepancy_reason', $data)) {
            $attributes['discrepancy_reason'] = DiscrepancyReason::tryFrom((string) $data['discrepancy_reason']);
        }

        if (array_key_exists('note', $data)) {
            $attributes['note'] = $data['note'];
        }

        $line->forceFill($attributes)->save();

        return $line;
    }

    public function removeLine(GoodsReceiptLine $line): void
    {
        $this->assertDraft($line->receipt);
        $this->assertCrmLines($line->receipt);

        DB::transaction(function () use ($line): void {
            $line->serialRows()->get()->each->delete();
            $line->delete();
        });
    }

    protected function assertCrmLines(GoodsReceipt $receipt): void
    {
        if ($receipt->isFromOneC()) {
            throw new StockActionException('receipt_lines_from_1c');
        }
    }

    /** Lines and serials change only while the receipt is `in_progress` (Spec 06 26.4). */
    public function assertDraft(GoodsReceipt $receipt): void
    {
        if (! $receipt->status->isEditable()) {
            throw new StockActionException($receipt->status === GoodsReceiptStatus::ReceivedFromOneC ? 'receipt_start_first' : 'receipt_not_editable');
        }
    }

    /**
     * Clears a review flag (1C changed a posted receipt, or asked to cancel one with serials):
     * the warehouse head confirms the follow-up (correction or keeping the receipt) with a note.
     */
    public function resolveReview(GoodsReceipt $receipt, User $user, string $note): GoodsReceipt
    {
        if (! $receipt->needs_review) {
            return $receipt;
        }

        if (trim($note) === '') {
            throw new StockActionException('receipt_reason_required');
        }

        $reason = $receipt->review_reason;
        $receipt->forceFill(['needs_review' => false, 'review_reason' => null])->save();
        $receipt->logActivity('review_resolved', ['reason' => $reason, 'note' => mb_substr(trim($note), 0, 500)]);

        return $receipt;
    }

    protected function quantity(string $value, bool $whole): string
    {
        $value = Quantity::normalize($value);

        if (! Quantity::isDecimal($value) || ($whole && ! Quantity::fitsPrecision($value, 0))) {
            throw new StockActionException($whole ? 'whole_quantity' : 'quantity_invalid');
        }

        return Quantity::toStorage($value);
    }
}
