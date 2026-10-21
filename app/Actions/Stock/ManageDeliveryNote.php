<?php

namespace App\Actions\Stock;

use App\Enums\ItemType;
use App\Enums\OrderStatus;
use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\ItemSerial;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Stock\Exceptions\StockActionException;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Delivery note drafts (Spec 06 7.6, 21.4, section 25: the draft is the packing list): drafted from an order (lines = open quantity not yet in
 * other drafts; serialized lines start at 0 and grow with each registered serial), quantities of
 * plain lines edited down, drafts cancelled (claimed serials released).
 */
class ManageDeliveryNote
{
    public function __construct(protected ManageSerials $serials) {}

    public function create(Order $order, User $user, ?int $warehouseId = null): Delivery
    {
        // Spec 06 section 25: the packing list is drafted once packaging has started; one open list at a time.
        if ($order->status !== OrderStatus::Packing) {
            throw new StockActionException('order_not_packing');
        }

        if (Delivery::query()->where('order_id', $order->id)->where('status', StockDocumentStatus::Draft)->exists()) {
            throw new StockActionException('packing_list_open');
        }

        $order->loadMissing('lines.item');
        $needsWarehouse = $order->lines->contains(fn (OrderLine $line): bool => $line->item?->type !== ItemType::Service);
        $warehouse = $warehouseId !== null ? Warehouse::query()->find($warehouseId) : Warehouse::query()->orderBy('id')->first();

        if ($needsWarehouse && $warehouse === null) {
            throw new StockActionException('warehouse_required');
        }

        return DB::transaction(function () use ($order, $user, $warehouse): Delivery {
            $year = (int) AccountingPeriods::today()->year;
            $delivery = Delivery::query()->create([
                'number' => NumberSequence::format('DN', $year, NumberSequence::next('DN', $year)),
                'order_id' => $order->id,
                'status' => StockDocumentStatus::Draft,
                'warehouse_id' => $warehouse?->id,
                'planned_on' => $order->promised_delivery_on?->toDateString(),
                'created_by' => $user->id,
            ]);

            $position = 1;

            foreach ($order->lines as $line) {
                $open = $this->openQuantity($line);

                if (bccomp($open, '0', Quantity::SCALE) <= 0) {
                    continue;
                }

                $delivery->lines()->create([
                    'order_line_id' => $line->id,
                    'position' => $position++,
                    'item_id' => $line->item_id,
                    'is_serialized' => $line->is_serialized || (bool) $line->item?->is_serialized,
                    'quantity' => ($line->is_serialized || $line->item?->is_serialized) ? '0' : $open,
                ]);
            }

            if ($position === 1) {
                throw new StockActionException('nothing_to_deliver');
            }

            return $delivery;
        });
    }

    /** Ordered − delivered − cancelled − quantities already on other open drafts. */
    public function openQuantity(OrderLine $line, ?int $exceptDeliveryLineId = null): string
    {
        $inDrafts = (string) (DeliveryLine::query()
            ->where('order_line_id', $line->id)
            ->when($exceptDeliveryLineId !== null, fn ($query) => $query->whereKeyNot($exceptDeliveryLineId))
            ->whereHas('delivery', fn ($query) => $query->where('status', StockDocumentStatus::Draft))
            ->sum('quantity') ?: '0');

        return bcsub($line->openQuantity(), $inDrafts, Quantity::SCALE);
    }

    public function updateLine(DeliveryLine $line, string $quantity): DeliveryLine
    {
        $this->assertEditable($line->delivery);

        if ($line->is_serialized) {
            throw new StockActionException('serialized_quantity_from_serials');
        }

        $quantity = Quantity::normalize($quantity);
        $orderLine = $line->orderLine;

        if (! Quantity::isDecimal($quantity) || ! Quantity::fitsPrecision($quantity, (int) $orderLine->unit_precision)) {
            throw new StockActionException('quantity_invalid');
        }

        if (bccomp($quantity, $this->openQuantity($orderLine, $line->id), Quantity::SCALE) > 0) {
            throw new StockActionException('exceeds_open', ['qty' => Quantity::trimmed($this->openQuantity($orderLine, $line->id))]);
        }

        $line->forceFill(['quantity' => Quantity::toStorage($quantity)])->save();

        return $line;
    }

    public function cancel(Delivery $delivery, User $user): Delivery
    {
        return DB::transaction(function () use ($delivery, $user): Delivery {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $this->assertDraft($delivery);

            $rows = DocumentLineSerial::query()->where('document_type', Delivery::DOCUMENT_TYPE)->where('document_id', $delivery->id)->where('status', DocumentLineSerial::DRAFT)->get();

            foreach ($rows as $row) {
                if ($row->item_serial_id !== null) {
                    $this->serials->release(ItemSerial::query()->lockForUpdate()->findOrFail($row->item_serial_id), $user, 'draft_cancelled', $row);
                }

                $row->forceFill(['status' => DocumentLineSerial::VOIDED, 'active_key' => null])->save();
            }

            $delivery->forceFill(['status' => StockDocumentStatus::Cancelled, 'cancelled_at' => now()])->save();

            return $delivery;
        });
    }

    /** Draft and not packed yet: quantities and serials may change (section 25). */
    public function assertEditable(Delivery $delivery): void
    {
        $this->assertDraft($delivery);

        if ($delivery->isPacked()) {
            throw new StockActionException('packing_locked');
        }
    }

    public function assertDraft(Delivery $delivery): void
    {
        if ($delivery->status !== StockDocumentStatus::Draft) {
            throw new StockActionException('not_draft');
        }
    }
}
