<?php

namespace App\Actions\Orders;

use App\Actions\Agreements\CancelAgreement;
use App\Actions\Stock\ManageDeliveryNote;
use App\Enums\AgreementStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\StockDocumentStatus;
use App\Models\Agreement;
use App\Models\Delivery;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\PaymentScheduleItem;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Cancel an order with a reason (Spec 06 6.1, BR-OR.10, AC-9): only before any delivery and with
 * no payment allocated (`paid_minor` = 0); releases active reservations and cancels agreements
 * not yet signed (draft, in review, sent for signature); outbox `order.cancelled` when 1C knew it.
 */
class CancelOrder
{
    public function __construct(protected TransitionOrder $transition, protected CancelAgreement $cancelAgreement) {}

    public function handle(Order $order, User $user, string $reason): Order
    {
        return DB::transaction(function () use ($order, $user, $reason): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
                throw new OrderActionException('invalid_transition', ['from' => $order->status->label(), 'to' => OrderStatus::Cancelled->label()]);
            }

            if ($order->lines()->get()->contains(fn ($line): bool => bccomp((string) $line->delivered_qty, '0', Quantity::SCALE) > 0)) {
                throw new OrderActionException('cancel_has_deliveries');
            }

            if ($order->paid_minor > 0) {
                throw new OrderActionException('cancel_has_payments');
            }

            // Draft delivery notes go too, releasing any serials they hold (BR-SN.8).
            Delivery::query()->where('order_id', $order->id)->where('status', StockDocumentStatus::Draft)->get()
                ->each(fn (Delivery $delivery) => app(ManageDeliveryNote::class)->cancel($delivery, $user));

            StockReservation::query()
                ->whereIn('order_line_id', $order->lines()->select('id'))
                ->whereIn('status', [ReservationStatus::Active, ReservationStatus::MirroredIn1c])
                ->update(['status' => ReservationStatus::Released, 'released_at' => now(), 'release_reason' => 'order_cancelled', 'updated_at' => now()]);

            Agreement::query()
                ->where('order_id', $order->id)
                ->whereIn('status', [AgreementStatus::Draft, AgreementStatus::InReview, AgreementStatus::SentForSignature])
                ->get()
                ->each(fn (Agreement $agreement) => $this->cancelAgreement->handle($agreement, $user, 'order_cancelled'));

            $this->transition->handle($order, OrderStatus::Cancelled, $user, $reason, [
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            PaymentScheduleItem::query()->where('order_id', $order->id)->whereNull('superseded_at')->update(['superseded_at' => now(), 'updated_at' => now()]);

            // 1C only hears about the cancellation of an order it was told about.
            if (OnecOutboxEvent::query()->where('aggregate_type', 'order')->where('aggregate_id', $order->id)->where('event_type', 'order.confirmed')->exists()) {
                app(OneCOutbox::class)->publishOrder('order.cancelled', $order);
            }

            return $order;
        });
    }
}
