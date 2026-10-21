<?php

namespace Tests\Feature\Api\OneC;

use App\Actions\Accounting\RecordPayment;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ConfirmOrder;
use App\Enums\IntegrationAbility;
use App\Enums\OutboxStatus;
use App\Enums\PaymentStatus;
use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Orders\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 06 section 9 outbox (DEC-14): abilities per feed (AC-32), head-of-line and re-delivery
 * (AC-33), acks, idempotency and posting updates (AC-34), reservations mirrored (AC-11),
 * customer precondition, payment confirmation by ack.
 */
class DocumentOutboxTest extends TestCase
{
    use BuildsOrders, InteractsWithOneCApi, RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableInbound();
        $this->rep = User::factory()->sales()->create();
    }

    private function linkedCustomer(): Customer
    {
        return Customer::factory()->ownedBy($this->rep)->create(['external_id' => $this->guid('cust-'.uniqid())]);
    }

    private function confirmedOrder(?Customer $customer = null): Order
    {
        $order = $this->simpleOrder($this->rep, '2', '10', $customer ?? $this->linkedCustomer());
        app(ConfirmOrder::class)->handle($order, $this->rep);

        return $order->fresh();
    }

    /** AC-32 */
    public function test_each_feed_needs_its_own_ability(): void
    {
        $this->confirmedOrder();
        $this->issueToken([IntegrationAbility::OrdersRead->value]);

        $this->onec('GET', '/outbox/pending?feed=payments')->assertForbidden()->assertJsonPath('error.code', 'forbidden_ability');
        $this->onec('GET', '/outbox/pending?feed=orders')->assertOk()->assertJsonPath('data.0.event_type', 'order.confirmed');
        $this->onec('GET', '/outbox/pending?feed=nope')->assertUnprocessable();
    }

    /** AC-33, AC-38 */
    public function test_head_of_line_per_aggregate_and_redelivery_until_acked(): void
    {
        $first = $this->confirmedOrder();
        app(CancelOrder::class)->handle($first, User::factory()->manager()->create(), 'Changed');
        $second = $this->confirmedOrder();
        $this->issueToken([IntegrationAbility::OrdersRead->value]);

        $page = $this->onec('GET', '/outbox/pending?feed=orders')->assertOk()->json('data');
        $this->assertSame([[$first->uuid, 'order.confirmed'], [$second->uuid, 'order.confirmed']], array_map(fn (array $e): array => [$e['aggregate']['crm_id'], $e['event_type']], $page));
        $this->assertArrayHasKey('accountant_code', $page[0]['payload']['lines'][0]);
        $this->assertArrayNotHasKey('internal_notes', $page[0]['payload']);
        $this->assertSame($first->customer->external_id, $page[0]['payload']['customer']['external_id']);

        $again = $this->onec('GET', '/outbox/pending?feed=orders')->json('data');
        $this->assertCount(2, $again, 'Unacked events come back on the next run.');
        $this->assertSame(2, OnecOutboxEvent::query()->where('event_id', $page[0]['event_id'])->value('delivered_count'));

        $this->onec('POST', '/outbox/ack', ['acks' => [['event_id' => $page[0]['event_id'], 'result' => 'applied', 'onec_guid' => $this->guid('order-1'), 'onec_number' => 'ZK-000123']]])->assertOk();

        $next = $this->onec('GET', '/outbox/pending?feed=orders')->json('data');
        $this->assertSame(['order.cancelled', 'order.confirmed'], array_column($next, 'event_type'));
    }

    /** AC-34, AC-11 */
    public function test_ack_stores_references_is_idempotent_and_takes_newer_posting_updates(): void
    {
        $order = $this->confirmedOrder();
        $itemId = $order->lines->first()->item_id;
        $this->assertSame('8.000', app(StockAvailability::class)->forItem($itemId));
        $this->issueToken([IntegrationAbility::OrdersRead->value]);
        $event = $this->onec('GET', '/outbox/pending?feed=orders')->json('data.0');
        $ack = ['event_id' => $event['event_id'], 'result' => 'applied', 'onec_guid' => $this->guid('order-x'), 'onec_number' => 'ZK-1', 'posting_status' => 'posted', 'updated_at' => '2026-10-18T10:17:00+05:00'];

        $this->onec('POST', '/outbox/ack', ['acks' => [$ack]])->assertOk()->assertJsonPath('results.0.result', 'applied');
        $this->onec('POST', '/outbox/ack', ['acks' => [$ack]])->assertOk()->assertJsonPath('results.0.result', 'applied');

        $order->refresh();
        $this->assertSame([$this->guid('order-x'), 'ZK-1', 'posted'], [$order->onec_guid, $order->onec_number, $order->onec_state]);
        $this->assertSame(ReservationStatus::MirroredIn1c, StockReservation::query()->sole()->status);
        $this->assertSame('10.000', app(StockAvailability::class)->forItem($itemId), 'Mirrored reservations are no longer subtracted.');

        $this->onec('POST', '/outbox/ack', ['acks' => [[...$ack, 'posting_status' => 'unposted', 'updated_at' => '2026-10-18T09:00:00+05:00']]])->assertOk();
        $this->assertSame('posted', $order->fresh()->onec_state, 'An older posting update is ignored.');
        $this->onec('POST', '/outbox/ack', ['acks' => [[...$ack, 'posting_status' => 'unposted', 'updated_at' => '2026-10-18T11:00:00+05:00']]])->assertOk();
        $this->assertSame('unposted', $order->fresh()->onec_state);
    }

    public function test_rejection_marks_the_order_and_unknown_events_are_reported(): void
    {
        $order = $this->confirmedOrder();
        $this->issueToken([IntegrationAbility::OrdersRead->value]);
        $event = $this->onec('GET', '/outbox/pending?feed=orders')->json('data.0');

        $this->onec('POST', '/outbox/ack', ['acks' => [
            ['event_id' => $event['event_id'], 'result' => 'rejected', 'errors' => [['code' => 'unknown_item', 'message' => 'item not found']]],
            ['event_id' => $this->guid('missing'), 'result' => 'applied'],
        ]])->assertStatus(207)->assertJsonPath('results.1.errors.0.code', 'unknown_event_id');

        $this->assertSame('rejected', $order->fresh()->onec_state);
        $this->assertStringContainsString('unknown_item', (string) $order->fresh()->onec_error);
    }

    public function test_events_of_customers_unknown_to_1c_wait_until_the_customer_is_linked(): void
    {
        $customer = Customer::factory()->ownedBy($this->rep)->create();
        $order = $this->confirmedOrder($customer);
        $this->issueToken([IntegrationAbility::OrdersRead->value]);

        $this->assertSame(OutboxStatus::BlockedCustomer, OnecOutboxEvent::query()->sole()->status);
        $this->assertSame([], $this->onec('GET', '/outbox/pending?feed=orders')->json('data'));

        $customer->update(['external_id' => $this->guid('late-customer')]);

        $this->assertSame([$order->uuid], array_column(array_column($this->onec('GET', '/outbox/pending?feed=orders')->json('data'), 'aggregate'), 'crm_id'));
    }

    public function test_payment_events_confirm_the_payment_when_applied(): void
    {
        $order = $this->confirmedOrder();
        $payment = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => 100_000, 'currency' => 'UZS', 'paid_on' => now('Asia/Tashkent')->toDateString(), 'method' => 'cash', 'payer_name' => 'Buyer'], User::factory()->accountant()->create());
        $this->issueToken([IntegrationAbility::PaymentsRead->value]);

        $event = $this->onec('GET', '/outbox/pending?feed=payments')->json('data.0');
        $this->assertSame(['payment.recorded', $payment->uuid], [$event['event_type'], $event['aggregate']['crm_id']]);
        $this->onec('POST', '/outbox/ack', ['acks' => [['event_id' => $event['event_id'], 'result' => 'applied', 'onec_guid' => $this->guid('pay-1'), 'onec_number' => 'PKO-1']]])->assertOk();

        $payment->refresh();
        $this->assertSame([PaymentStatus::Confirmed, $this->guid('pay-1'), 'PKO-1'], [$payment->status, $payment->onec_guid, $payment->onec_number]);
    }
}
