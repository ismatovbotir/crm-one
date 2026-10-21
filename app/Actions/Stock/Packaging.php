<?php

namespace App\Actions\Stock;

use App\Actions\Orders\TransitionOrder;
use App\Enums\OrderStatus;
use App\Enums\SerialStatus;
use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\ItemSerial;
use App\Models\Order;
use App\Models\User;
use App\Services\Stock\Exceptions\StockActionException;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Packaging step (Spec 06 section 25, gate G3): the packing list is the draft delivery note of an
 * order in `packing`. Completing it requires at least one quantity and, on every serialized line,
 * one registered serial per unit still reserved by that line, for the whole open quantity (only
 * backorder lines may be packed partly). The list is then locked, the order becomes `packed` and
 * delivery may be posted. Reopening unlocks the list and returns the order to `packing`.
 */
class Packaging
{
    public function __construct(protected TransitionOrder $transition) {}

    /**
     * Lines that still block completion, as "position. name (registered / needed)".
     *
     * @return list<string>
     */
    public function incomplete(Delivery $delivery): array
    {
        $open = [];

        foreach ($delivery->lines()->with(['item', 'orderLine'])->get()->where('is_serialized', true) as $line) {
            $rows = $line->serialRows()->pluck('item_serial_id');
            $reserved = ItemSerial::query()->whereKey($rows->filter()->all())->where('status', SerialStatus::Reserved)
                ->where('reserved_by_line_type', DeliveryLine::LINE_TYPE)->where('reserved_by_line_id', $line->id)->count();
            $needed = $line->orderLine?->backorder ? (string) $line->quantity : (string) $line->orderLine?->openQuantity();

            if ($reserved !== $rows->count() || bccomp((string) $rows->count(), (string) $line->quantity, Quantity::SCALE) !== 0 || bccomp((string) $rows->count(), $needed, Quantity::SCALE) < 0) {
                $open[] = $line->position.'. '.$line->item?->name.' ('.$reserved.' / '.Quantity::trimmed($needed).')';
            }
        }

        return $open;
    }

    public function complete(Delivery $delivery, User $user): Delivery
    {
        return DB::transaction(function () use ($delivery, $user): Delivery {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $order = Order::query()->lockForUpdate()->findOrFail($delivery->order_id);

            if ($delivery->status !== StockDocumentStatus::Draft || $delivery->isPacked()) {
                throw new StockActionException('packing_not_open');
            }

            if ($order->status !== OrderStatus::Packing) {
                throw new StockActionException('order_not_packing');
            }

            if ($delivery->lines()->get()->every(fn (DeliveryLine $line): bool => bccomp((string) $line->quantity, '0', Quantity::SCALE) <= 0)) {
                throw new StockActionException('delivery_empty');
            }

            $incomplete = $this->incomplete($delivery);

            if ($incomplete !== []) {
                throw new StockActionException('serials_incomplete', ['lines' => implode('; ', $incomplete)]);
            }

            $delivery->forceFill(['packed_at' => now(), 'packed_by' => $user->id])->save();
            $delivery->logActivity('packed');
            $this->transition->handle($order, OrderStatus::Packed, $user, 'packed:'.$delivery->number, ['packed_at' => now()]);

            return $delivery;
        });
    }

    public function reopen(Delivery $delivery, User $user, string $reason): Delivery
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new StockActionException('reopen_reason_required');
        }

        return DB::transaction(function () use ($delivery, $user, $reason): Delivery {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $order = Order::query()->lockForUpdate()->findOrFail($delivery->order_id);

            if ($delivery->status !== StockDocumentStatus::Draft || ! $delivery->isPacked() || $order->status !== OrderStatus::Packed) {
                throw new StockActionException('packing_not_reopenable');
            }

            $delivery->forceFill(['packed_at' => null, 'packed_by' => null])->save();
            $delivery->logActivity('packing_reopened', ['reason' => $reason]);
            $this->transition->handle($order, OrderStatus::Packing, $user, $reason, ['packed_at' => null]);

            return $delivery;
        });
    }
}
