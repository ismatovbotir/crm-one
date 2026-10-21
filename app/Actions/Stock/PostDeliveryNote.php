<?php

namespace App\Actions\Stock;

use App\Actions\Orders\TransitionOrder;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\SerialStatus;
use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\ItemSerial;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\OrderPayments;
use App\Services\Equipment\AssetRegister;
use App\Services\Notifications\Notifier;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Stock\DeliverySchedule;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialLog;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Posts the sales document (Spec 06 DEC-16, BR-OR.8, BR-SN.3/7, AC-S6, AC-7, section 25): the
 * order is `packed` and this packing list complete; every serialized line is fully registered (quantity = serial rows, every serial still
 * reserved by this line); quantities fit the open quantity. Effects: serials `reserved → sold`
 * (customer, order line, warranty), rows posted, order `delivered_qty`, reservations consumed, order
 * → partially delivered / delivered, on-delivery schedule item, outbox `delivery.posted` with serials.
 */
class PostDeliveryNote
{
    public function __construct(
        protected TransitionOrder $transition,
        protected SerialLog $log,
        protected OneCOutbox $outbox,
        protected DeliverySchedule $schedule,
        protected OrderPayments $orderPayments,
    ) {}

    public function handle(Delivery $delivery, User $user): Delivery
    {
        $posted = DB::transaction(function () use ($delivery, $user): Delivery {
            $delivery = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            $order = Order::query()->lockForUpdate()->findOrFail($delivery->order_id);

            if ($delivery->status !== StockDocumentStatus::Draft) {
                throw new StockActionException('not_draft');
            }

            // Spec 06 section 25 gate G4: delivery only after packaging is complete.
            if ($order->status !== OrderStatus::Packed || ! $delivery->isPacked()) {
                throw new StockActionException('not_packed');
            }

            // Spec 06 section 31: self pick-up records who collected the goods and their document.
            if ($order->isPickup() && (blank($delivery->pickup_person) || blank($delivery->pickup_document))) {
                throw new StockActionException('pickup_details_required');
            }

            $lines = $delivery->lines()->with(['orderLine', 'item'])->get()->filter(fn (DeliveryLine $line): bool => bccomp((string) $line->quantity, '0', Quantity::SCALE) > 0 || $line->is_serialized);
            $this->guard($delivery, $lines);

            $today = AccountingPeriods::today();

            foreach ($lines as $line) {
                if (bccomp((string) $line->quantity, '0', Quantity::SCALE) <= 0) {
                    continue;
                }

                $orderLine = OrderLine::query()->lockForUpdate()->findOrFail($line->order_line_id);

                if (bccomp((string) $line->quantity, $orderLine->openQuantity(), Quantity::SCALE) > 0) {
                    throw new StockActionException('exceeds_open', ['qty' => Quantity::trimmed($orderLine->openQuantity())]);
                }

                if ($line->is_serialized) {
                    $this->sellSerials($delivery, $line, $order, $user, $today->toDateString());
                }

                $orderLine->forceFill(['delivered_qty' => bcadd((string) $orderLine->delivered_qty, (string) $line->quantity, Quantity::SCALE)])->save();
                $this->consumeReservations($orderLine, (string) $line->quantity);
            }

            $delivery->forceFill(['status' => StockDocumentStatus::Posted, 'posted_by' => $user->id, 'posted_at' => now(), 'delivered_on' => $today->toDateString()])->save();

            $complete = $order->lines()->get()->every(fn (OrderLine $line): bool => $line->isFullyDelivered());
            $target = $complete ? OrderStatus::Delivered : OrderStatus::PartiallyDelivered;

            if ($order->status !== $target) {
                $this->transition->handle($order, $target, $user, 'delivery:'.$delivery->number, $complete ? ['delivered_at' => now()] : []);
            }
            $this->schedule->onPosted($order, $delivery, $complete);
            $this->orderPayments->recompute($order->fresh());
            $this->outbox->publishDelivery($delivery->fresh());

            return $delivery;
        });

        // Spec 06 section 29: shipping an order that is not fully paid tells the accountants.
        $order = $posted->order()->first();

        if ($order !== null && $order->payment_status !== OrderPaymentStatus::Paid) {
            app(Notifier::class)->shippedUnpaid($order, $posted, $user);
        }

        return $posted;
    }

    /**
     * @param  Collection<int, DeliveryLine>  $lines
     */
    protected function guard(Delivery $delivery, $lines): void
    {
        if ($lines->every(fn (DeliveryLine $line): bool => bccomp((string) $line->quantity, '0', Quantity::SCALE) <= 0)) {
            throw new StockActionException('delivery_empty');
        }

        $incomplete = [];

        foreach ($lines->where('is_serialized', true) as $line) {
            $rows = $line->serialRows()->pluck('item_serial_id');
            $reserved = ItemSerial::query()->whereKey($rows->all())->where('status', SerialStatus::Reserved)
                ->where('reserved_by_line_type', DeliveryLine::LINE_TYPE)->where('reserved_by_line_id', $line->id)->count();

            if (bccomp((string) $rows->count(), (string) $line->quantity, Quantity::SCALE) !== 0 || $reserved !== $rows->count()) {
                $incomplete[] = $line->position.'. '.$line->item?->name.' ('.$reserved.' / '.Quantity::trimmed((string) $line->quantity).')';
            }
        }

        if ($incomplete !== []) {
            throw new StockActionException('serials_incomplete', ['lines' => implode('; ', $incomplete)]);
        }
    }

    protected function sellSerials(Delivery $delivery, DeliveryLine $line, Order $order, User $user, string $today): void
    {
        $months = (int) ($line->item?->warranty_months ?? 0);
        $rows = $line->serialRows()->get();
        $serials = ItemSerial::query()->whereKey($rows->pluck('item_serial_id')->all())->orderBy('id')->lockForUpdate()->get();

        foreach ($serials as $serial) {
            $serial->forceFill([
                'status' => SerialStatus::Sold,
                'warehouse_id' => null,
                'reserved_by_line_type' => null,
                'reserved_by_line_id' => null,
                'sold_delivery_line_id' => $line->id,
                'sold_order_line_id' => $line->order_line_id,
                'customer_id' => $order->customer_id,
                'sold_at' => now(),
                'warranty_until' => $months > 0 ? CarbonImmutable::parse($today)->addMonths($months)->toDateString() : null,
                'lock_version' => $serial->lock_version + 1,
            ])->save();

            $this->log->record($serial, 'sold', SerialStatus::Reserved, $user, ['document_type' => Delivery::DOCUMENT_TYPE, 'document_id' => $delivery->id, 'line_id' => $line->id, 'customer_id' => $order->customer_id, 'warehouse_from_id' => $delivery->warehouse_id]);

            // Spec 09 §4.1: the unit appears in the customer's equipment register (no typing).
            app(AssetRegister::class)->fromSoldSerial($serial, $delivery, $order, $user);
        }

        DocumentLineSerial::query()->whereKey($rows->modelKeys())->update(['status' => DocumentLineSerial::POSTED]);
    }

    /** Reservations of the order line shrink by the delivered quantity (BR-OR.7). */
    protected function consumeReservations(OrderLine $line, string $quantity): void
    {
        $remaining = $quantity;

        foreach (StockReservation::query()->where('order_line_id', $line->id)->whereIn('status', [ReservationStatus::Active, ReservationStatus::MirroredIn1c])->orderBy('id')->lockForUpdate()->get() as $reservation) {
            if (bccomp($remaining, '0', Quantity::SCALE) <= 0) {
                break;
            }

            $take = bccomp((string) $reservation->quantity, $remaining, Quantity::SCALE) <= 0 ? (string) $reservation->quantity : $remaining;
            $left = bcsub((string) $reservation->quantity, $take, Quantity::SCALE);

            if (bccomp($left, '0', Quantity::SCALE) === 0) {
                $reservation->forceFill(['status' => ReservationStatus::Consumed, 'released_at' => now(), 'release_reason' => 'delivered'])->save();
            } else {
                $reservation->forceFill(['quantity' => $left])->save();
                StockReservation::query()->create(['order_line_id' => $line->id, 'item_id' => $reservation->item_id, 'warehouse_id' => $reservation->warehouse_id, 'quantity' => $take, 'status' => ReservationStatus::Consumed, 'released_at' => now(), 'release_reason' => 'delivered']);
            }

            $remaining = bcsub($remaining, $take, Quantity::SCALE);
        }
    }
}
