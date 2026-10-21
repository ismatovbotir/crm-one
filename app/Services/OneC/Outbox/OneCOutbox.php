<?php

namespace App\Services\OneC\Outbox;

use App\Actions\Accounting\RecomputeAfterPaymentChange;
use App\Enums\IntegrationAbility;
use App\Enums\OrderStatus;
use App\Enums\OutboxStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\ServiceTicket;
use App\Models\StockAdjustment;
use App\Models\StockOffAct;
use App\Models\StockReservation;
use App\Services\OneC\Inbound\BatchValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transactional outbox of CRM documents for 1C (Spec 06 DEC-14, 9.2). Events are written in the
 * caller's transaction; 1C polls per feed (head-of-line per aggregate, at-least-once) and acks.
 * Order-feed events of a customer without a 1C GUID wait as `blocked_customer` until the
 * customer outbox ack links it. The CRM never calls 1C.
 */
class OneCOutbox
{
    /** @var array<string, IntegrationAbility> feed => read ability */
    public const FEEDS = [
        'orders' => IntegrationAbility::OrdersRead,
        'payments' => IntegrationAbility::PaymentsRead,
        'warehouse' => IntegrationAbility::WarehouseRead,
    ];

    public const RESULTS = ['applied', 'already_applied', 'superseded', 'rejected'];

    public function __construct(protected OutboxPayloads $payloads) {}

    public function publishOrder(string $eventType, Order $order): OnecOutboxEvent
    {
        return $this->publish($eventType, $order, $this->payloads->order($order), (int) $order->revision, $order->customer);
    }

    public function publishInvoice(string $eventType, Invoice $invoice): OnecOutboxEvent
    {
        return $this->publish($eventType, $invoice, $this->payloads->invoice($invoice), 1, $invoice->customer);
    }

    public function publishPayment(string $eventType, Payment $payment): OnecOutboxEvent
    {
        return $this->publish($eventType, $payment, $this->payloads->payment($payment), 1, $payment->customer);
    }

    public function publishDelivery(Delivery $delivery): OnecOutboxEvent
    {
        return $this->publish('delivery.posted', $delivery, $this->payloads->delivery($delivery), 1, $delivery->order?->customer);
    }

    /** `goods_receipt.posted` or `goods_receipt.cancelled` (a 1C receipt cancelled in the CRM). */
    public function publishGoodsReceipt(GoodsReceipt $receipt, string $eventType = 'goods_receipt.posted'): OnecOutboxEvent
    {
        return $this->publish($eventType, $receipt, $this->payloads->goodsReceipt($receipt), max(1, (int) $receipt->sync_version), null);
    }

    /** Spec 06 27.4: a posted stock-off act, available for 1C to pull (feed `warehouse`). */
    public function publishStockOff(StockOffAct $act): OnecOutboxEvent
    {
        return $this->publish('stock_off.posted', $act, $this->payloads->stockOff($act), 1, null);
    }

    /** Spec 06 21.4: a posted stock adjustment (found serials, serial or quantity correction), available for 1C to pull (feed `warehouse`). */
    public function publishStockAdjustment(StockAdjustment $adjustment): OnecOutboxEvent
    {
        return $this->publish('stock_adjustment.posted', $adjustment, $this->payloads->stockAdjustment($adjustment), 1, null);
    }

    public function publishAgreement(string $eventType, Agreement $agreement): OnecOutboxEvent
    {
        return $this->publish($eventType, $agreement, $this->payloads->agreement($agreement), (int) $agreement->version, $agreement->customer);
    }

    /** Spec 09 §10: billable repair closed outside warranty, available for 1C to pull (feed orders). */
    public function publishServiceTicket(ServiceTicket $ticket): OnecOutboxEvent
    {
        return $this->publish('service_ticket.closed', $ticket, $this->payloads->serviceTicket($ticket), 1, $ticket->customer);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $eventType, Model $aggregate, array $payload, int $version, ?Partner $customer): OnecOutboxEvent
    {
        $feed = match (true) {
            str_starts_with($eventType, 'payment.') => 'payments',
            str_starts_with($eventType, 'goods_receipt.'), str_starts_with($eventType, 'stock_') => 'warehouse',
            default => 'orders',
        };
        $blocked = $feed === 'orders' && $customer !== null && ! $customer->isLinkedToOneC();

        $event = OnecOutboxEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregate->getMorphClass(),
            'aggregate_id' => $aggregate->getKey(),
            'aggregate_uuid' => (string) $aggregate->getAttribute('uuid'),
            'aggregate_version' => max(1, $version),
            'customer_id' => $customer?->id,
            'feed' => $feed,
            'payload' => $payload,
            'payload_hash' => hash('sha256', (string) json_encode($payload)),
            'status' => $blocked ? OutboxStatus::BlockedCustomer : OutboxStatus::Pending,
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        if ($aggregate->getAttribute('onec_state') === 'not_sent' || $aggregate->getAttribute('onec_state') === 'rejected') {
            $aggregate->forceFill(['onec_state' => 'queued'])->saveQuietly();
        }

        return $event;
    }

    /** The customer reached 1C: its held order-feed events become pending (9.2 customer precondition). */
    public function releaseCustomer(Partner $customer): int
    {
        return OnecOutboxEvent::query()->where('customer_id', $customer->id)->where('status', OutboxStatus::BlockedCustomer)->update(['status' => OutboxStatus::Pending]);
    }

    /**
     * Open events of a feed after the cursor, oldest first, skipping aggregates with an earlier
     * open or held event (head-of-line). Returned events move to `delivered`.
     *
     * @return array{events: Collection<int, OnecOutboxEvent>, has_more: bool}
     */
    public function poll(string $feed, int $cursor, int $limit): array
    {
        return DB::transaction(function () use ($feed, $cursor, $limit): array {
            $candidates = OnecOutboxEvent::query()
                ->where('feed', $feed)
                ->whereIn('status', [OutboxStatus::Pending, OutboxStatus::Delivered])
                ->where('id', '>', $cursor)
                ->whereNotExists(fn ($earlier) => $earlier->from('onec_outbox as earlier')
                    ->whereColumn('earlier.aggregate_type', 'onec_outbox.aggregate_type')
                    ->whereColumn('earlier.aggregate_id', 'onec_outbox.aggregate_id')
                    ->whereColumn('earlier.id', '<', 'onec_outbox.id')
                    ->whereIn('earlier.status', [OutboxStatus::Pending->value, OutboxStatus::Delivered->value, OutboxStatus::BlockedCustomer->value]))
                ->orderBy('id')
                ->limit($limit + 1)
                ->lockForUpdate()
                ->get();

            $hasMore = $candidates->count() > $limit;
            $events = $candidates->take($limit)->values();
            $now = now();

            foreach ($events as $event) {
                $event->forceFill([
                    'status' => OutboxStatus::Delivered,
                    'delivered_count' => $event->delivered_count + 1,
                    'first_delivered_at' => $event->first_delivered_at ?? $now,
                    'last_delivered_at' => $now,
                ])->save();
            }

            return ['events' => new Collection($events->all()), 'has_more' => $hasMore];
        });
    }

    /**
     * Applies one ack (9.2): applied / already_applied / superseded store the 1C references,
     * rejected marks the document; later posting updates with a newer `updated_at` win.
     *
     * @param  array<string, mixed>  $ack
     * @param  list<string>  $allowedFeeds
     * @return array{event_id: string|null, result: string, errors?: list<array{field: string|null, code: string}>}
     */
    public function acknowledge(array $ack, array $allowedFeeds): array
    {
        $eventId = is_string($ack['event_id'] ?? null) ? strtolower($ack['event_id']) : null;
        $result = $ack['result'] ?? null;

        if ($eventId === null || preg_match(BatchValidator::GUID_PATTERN, $eventId) !== 1 || ! in_array($result, self::RESULTS, true)) {
            return ['event_id' => $eventId, 'result' => 'rejected', 'errors' => [['field' => $eventId === null ? 'event_id' : 'result', 'code' => 'validation_failed']]];
        }

        foreach (['onec_guid', 'onec_number', 'updated_at'] as $field) {
            if (isset($ack[$field]) && ! is_string($ack[$field])) {
                return ['event_id' => $eventId, 'result' => 'rejected', 'errors' => [['field' => $field, 'code' => 'validation_failed']]];
            }
        }

        $guid = isset($ack['onec_guid']) ? strtolower((string) $ack['onec_guid']) : null;

        if ($guid !== null && preg_match(BatchValidator::GUID_PATTERN, $guid) !== 1) {
            return ['event_id' => $eventId, 'result' => 'rejected', 'errors' => [['field' => 'onec_guid', 'code' => 'validation_failed']]];
        }

        return DB::transaction(function () use ($eventId, $result, $ack, $guid, $allowedFeeds): array {
            $event = OnecOutboxEvent::query()->where('event_id', $eventId)->lockForUpdate()->first();

            if ($event === null) {
                return ['event_id' => $eventId, 'result' => 'rejected', 'errors' => [['field' => 'event_id', 'code' => 'unknown_event_id']]];
            }

            if (! in_array($event->feed, $allowedFeeds, true)) {
                return ['event_id' => $eventId, 'result' => 'rejected', 'errors' => [['field' => 'event_id', 'code' => 'feed_not_allowed']]];
            }

            $updatedAt = is_string($ack['updated_at'] ?? null) ? CarbonImmutable::parse($ack['updated_at'])->utc() : now()->toImmutable();
            $posting = in_array($ack['posting_status'] ?? null, ['posted', 'unposted', 'deleted'], true) ? $ack['posting_status'] : null;

            if ($event->status === OutboxStatus::Acked || $event->status === OutboxStatus::Rejected) {
                // Re-ack (idempotent) or a posting update of an applied event (newer wins).
                if ($event->status === OutboxStatus::Acked && $posting !== null && ($event->posting_updated_at === null || $updatedAt->gt($event->posting_updated_at))) {
                    $event->forceFill(['posting_status' => $posting, 'posting_updated_at' => $updatedAt])->save();
                    $this->applyToAggregate($event, $result, $guid, $ack, $posting);
                }

                return ['event_id' => $eventId, 'result' => 'applied'];
            }

            $rejected = $result === 'rejected';
            $event->forceFill([
                'status' => $rejected ? OutboxStatus::Rejected : OutboxStatus::Acked,
                'acked_at' => now(),
                'ack_result' => $result,
                'ack_payload' => array_intersect_key($ack, array_flip(['result', 'onec_guid', 'onec_number', 'posting_status', 'updated_at', 'errors'])),
                'onec_guid' => $guid,
                'onec_number' => is_string($ack['onec_number'] ?? null) ? mb_substr($ack['onec_number'], 0, 40) : null,
                'posting_status' => $posting,
                'posting_updated_at' => $posting !== null ? $updatedAt : null,
                'error' => $rejected ? mb_substr((string) json_encode($ack['errors'] ?? []), 0, 250) : null,
            ])->save();

            $this->applyToAggregate($event, $result, $guid, $ack, $posting);

            return ['event_id' => $eventId, 'result' => 'applied'];
        });
    }

    /**
     * @param  array<string, mixed>  $ack
     */
    protected function applyToAggregate(OnecOutboxEvent $event, string $result, ?string $guid, array $ack, ?string $posting): void
    {
        $model = match ($event->aggregate_type) {
            'order' => Order::query()->find($event->aggregate_id),
            'invoice' => Invoice::query()->find($event->aggregate_id),
            'payment' => Payment::query()->find($event->aggregate_id),
            'agreement' => Agreement::query()->find($event->aggregate_id),
            'delivery' => Delivery::query()->find($event->aggregate_id),
            'goods_receipt' => GoodsReceipt::query()->find($event->aggregate_id),
            'stock_off_act' => StockOffAct::query()->find($event->aggregate_id),
            'service_ticket' => ServiceTicket::query()->find($event->aggregate_id),
            default => null,
        };

        if ($model === null) {
            return;
        }

        $state = match (true) {
            $result === 'rejected' => 'rejected',
            $posting === 'deleted' => 'deleted',
            $posting === 'unposted' => 'unposted',
            $posting === 'posted' => 'posted',
            default => 'acked',
        };

        $attributes = ['onec_state' => $state];

        if ($guid !== null && $model->getAttribute('onec_guid') === null && ! $model::query()->where('onec_guid', $guid)->exists()) {
            $attributes['onec_guid'] = $guid;
        }

        if (is_string($ack['onec_number'] ?? null) && in_array('onec_number', array_keys($model->getAttributes()), true)) {
            $attributes['onec_number'] = mb_substr($ack['onec_number'], 0, 30);
        }

        if ($model instanceof Order || $model instanceof Agreement || $model instanceof Payment || $model instanceof Delivery || $model instanceof GoodsReceipt || $model instanceof StockOffAct || $model instanceof ServiceTicket) {
            $attributes['onec_acked_at'] = now();
        }

        if ($model instanceof Order) {
            $attributes['onec_error'] = $result === 'rejected' ? mb_substr((string) json_encode($ack['errors'] ?? []), 0, 250) : null;

            // AC-11: once 1C applied the confirmed order it reserves itself; CRM rows stop counting.
            if ($result !== 'rejected' && $event->event_type === 'order.confirmed' && $model->status !== OrderStatus::Cancelled) {
                StockReservation::query()->whereIn('order_line_id', $model->lines()->select('id'))->where('status', ReservationStatus::Active)
                    ->update(['status' => $posting === 'deleted' ? ReservationStatus::Active : ReservationStatus::MirroredIn1c, 'updated_at' => now()]);
            }

            if ($posting === 'deleted') {
                StockReservation::query()->whereIn('order_line_id', $model->lines()->select('id'))->where('status', ReservationStatus::MirroredIn1c)
                    ->update(['status' => ReservationStatus::Active, 'updated_at' => now()]);
            }
        }

        if ($model instanceof Payment && in_array($event->event_type, ['payment.recorded', 'payment.refund_recorded'], true)) {
            unset($attributes['onec_state']);

            if ($result === 'rejected' && $model->status->canTransitionTo(PaymentStatus::OnecRejected)) {
                $attributes['status'] = PaymentStatus::OnecRejected;
                $attributes['onec_error'] = mb_substr((string) json_encode($ack['errors'] ?? []), 0, 250);
            } elseif ($result !== 'rejected' && $model->status->canTransitionTo(PaymentStatus::Confirmed)) {
                $attributes['status'] = PaymentStatus::Confirmed;
            }
        }

        if ($model instanceof Payment && isset($attributes['onec_number'])) {
            $attributes['onec_number'] = mb_substr((string) $attributes['onec_number'], 0, 40);
        }

        $model->forceFill($attributes)->save();

        if ($model instanceof Payment && isset($attributes['status'])) {
            app(RecomputeAfterPaymentChange::class)->handle($model);
        }
    }

    /**
     * Monitor figures for Settings > Integrations (9.4 staleness).
     *
     * @return array{open: int, blocked: int, rejected: int, oldest_open_at: CarbonImmutable|null}
     */
    public function stats(): array
    {
        $open = OnecOutboxEvent::query()->whereIn('status', [OutboxStatus::Pending, OutboxStatus::Delivered]);

        return [
            'open' => (clone $open)->count(),
            'blocked' => OnecOutboxEvent::query()->where('status', OutboxStatus::BlockedCustomer)->count(),
            'rejected' => OnecOutboxEvent::query()->where('status', OutboxStatus::Rejected)->where('created_at', '>=', now()->subDays(30))->count(),
            'oldest_open_at' => (clone $open)->orderBy('id')->value('occurred_at') !== null ? CarbonImmutable::parse((clone $open)->orderBy('id')->value('occurred_at')) : null,
        ];
    }

    /**
     * @param  Builder<OnecOutboxEvent>  $query
     * @return Builder<OnecOutboxEvent>
     */
    public static function forAggregate(Builder $query, Model $aggregate): Builder
    {
        return $query->where('aggregate_type', $aggregate->getMorphClass())->where('aggregate_id', $aggregate->getKey());
    }
}
