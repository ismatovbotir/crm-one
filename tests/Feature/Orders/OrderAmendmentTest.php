<?php

namespace Tests\Feature\Orders;

use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Agreements\TransitionAgreement;
use App\Actions\OrderAmendments\ManageOrderAmendment;
use App\Actions\OrderAmendments\TransitionOrderAmendment;
use App\Actions\Orders\StartPackaging;
use App\Actions\Orders\WaiveAgreement;
use App\Actions\Stock\ManageDeliveryNote;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\OrderAmendmentStatus;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Livewire\Orders\Amendments;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Orders\Exceptions\OrderAmendmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 section 6.4 / AC-10: amendments of a started order (quantity, discount, new line, remove,
 * close short) with the discount limit approval, recalculated totals, reservations and schedule, a
 * new revision and the `order.amended` event available for 1C to pull.
 */
class OrderAmendmentTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $rep;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
        $this->manager = User::factory()->manager()->create();
    }

    private function amend(Order $order, array $changes, ?User $by = null, string $reason = 'Customer asked'): OrderAmendment
    {
        $by ??= $this->rep;
        $manage = app(ManageOrderAmendment::class);
        $amendment = $manage->start($order, $by, $reason);

        foreach ($changes as $change) {
            $manage->addChange($amendment, $change);
        }

        return $amendment->fresh();
    }

    private function expectError(string $key, callable $action): void
    {
        try {
            $action();
            $this->fail('Expected order amendment error '.$key);
        } catch (OrderAmendmentException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }

    public function test_a_quantity_change_within_the_limit_applies_at_once(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $line = $order->lines()->sole();
        $grossBefore = $order->gross_minor;
        $reserved = StockReservation::query()->where('order_line_id', $line->id)->where('status', ReservationStatus::Active)->sum('quantity');
        $this->assertSame(10.0, (float) $reserved);

        $amendment = $this->amend($order, [['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '15']]);
        $applied = app(TransitionOrderAmendment::class)->submit($amendment, $this->rep);

        $this->assertSame(OrderAmendmentStatus::Applied, $applied->status);
        $order->refresh();
        $line->refresh();
        $this->assertSame('15.000', (string) $line->quantity);
        $this->assertSame(2, $order->revision);
        $this->assertSame((int) round($grossBefore * 1.5), $order->gross_minor);
        $this->assertSame($order->gross_minor, (int) $order->lines()->sum('line_gross_minor'));
        $this->assertSame($order->gross_minor, (int) $order->scheduleItems()->sum('amount_minor'), 'The schedule covers the new total.');
        $this->assertSame(15.0, (float) StockReservation::query()->where('order_line_id', $line->id)->where('status', ReservationStatus::Active)->sum('quantity'));
        $this->assertSame([1, 2], [$applied->revision_from, $applied->revision_to]);
        $this->assertSame($grossBefore, $applied->totals_before['gross_minor']);
        $this->assertSame($order->gross_minor, $applied->totals_after['gross_minor']);

        $event = OnecOutboxEvent::query()->where('event_type', 'order.amended')->sole();
        $this->assertSame(2, $event->aggregate_version);
        $this->assertSame('15.000', $event->payload['lines'][0]['quantity']);
        $this->assertDatabaseHas('status_transitions', ['subject_type' => 'order_amendment', 'subject_id' => $amendment->id, 'to_status' => 'applied']);
    }

    public function test_a_quantity_cannot_go_below_what_sits_on_a_packing_list_or_was_delivered(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        app(WaiveAgreement::class)->handle($order, $this->manager, 'test');
        app(StartPackaging::class)->handle($order->fresh(), $this->rep);
        $line = $order->lines()->sole();
        $delivery = app(ManageDeliveryNote::class)->create($order->fresh(), $this->rep, Warehouse::factory()->create()->id);
        $this->assertSame(10.0, (float) $delivery->lines()->sole()->quantity);

        $manage = app(ManageOrderAmendment::class);
        $amendment = $manage->start($order->fresh(), $this->rep, 'Fewer');
        $this->expectError('quantity_below_committed', fn () => $manage->addChange($amendment, ['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '6']));
        $this->expectError('nothing_changed', fn () => $manage->addChange($amendment, ['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '10']));
        $this->expectError('line_in_use', fn () => $manage->addChange($amendment, ['type' => 'remove_line', 'order_line_id' => $line->id]));
        $manage->addChange($amendment, ['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '12']);
        $this->expectError('line_already_changed', fn () => $manage->addChange($amendment->fresh(), ['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '13']));
        $this->expectError('close_short_over_open', fn () => $manage->addChange($amendment->fresh(), ['type' => 'close_short', 'order_line_id' => $line->id, 'quantity' => '1']));
    }

    public function test_a_discount_above_the_limit_waits_for_an_approver_who_is_not_the_requester(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $line = $order->lines()->sole();
        $grossBefore = $order->gross_minor;
        $amendment = $this->amend($order, [['type' => 'price_change', 'order_line_id' => $line->id, 'discount_bp' => 1500]]);
        $moves = app(TransitionOrderAmendment::class);

        $pending = $moves->submit($amendment, $this->rep);
        $this->assertSame([OrderAmendmentStatus::PendingApproval, true, 1500], [$pending->status, $pending->needs_approval, $pending->required_discount_bp]);
        $this->assertSame($grossBefore, $order->fresh()->gross_minor);

        $this->expectError('own_amendment', fn () => $moves->approve($pending->fresh(), $this->rep));
        $otherRep = User::factory()->sales()->create();
        $this->expectError('forbidden', fn () => $moves->approve($pending->fresh(), $otherRep));
        $moves->approve($pending->fresh(), $this->manager, 'Agreed with the customer');

        $order->refresh();
        $this->assertSame(1500, $order->lines()->sole()->discount_bp);
        $this->assertSame((int) round($grossBefore * 0.85), $order->gross_minor);
        $this->assertSame(2, $order->revision);
        $applied = $pending->fresh();
        $this->assertSame([OrderAmendmentStatus::Applied, $this->manager->id], [$applied->status, $applied->decided_by]);
    }

    public function test_a_discount_above_the_approver_limit_or_rejecting_changes_nothing(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $line = $order->lines()->sole();
        $moves = app(TransitionOrderAmendment::class);
        $pending = $moves->submit($this->amend($order, [['type' => 'price_change', 'order_line_id' => $line->id, 'discount_bp' => 3000]]), $this->rep);

        $this->expectError('approver_limit', fn () => $moves->approve($pending->fresh(), $this->manager));
        $this->expectError('reason_required', fn () => $moves->reject($pending->fresh(), $this->manager, ' '));
        $moves->reject($pending->fresh(), $this->manager, 'Too deep');

        $this->assertSame(OrderAmendmentStatus::Rejected, $pending->fresh()->status);
        $this->assertSame(0, $line->fresh()->discount_bp);
        $this->assertSame(1, $order->fresh()->revision);
        $this->assertSame(0, OnecOutboxEvent::query()->where('event_type', 'order.amended')->count());
        $this->amend($order->fresh(), []);
    }

    public function test_lines_can_be_added_removed_and_closed_short(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $first = $order->lines()->sole();
        $extra = $this->pricedItem('500.00', ['name' => 'Valve'], '50');

        $amendment = $this->amend($order, [
            ['type' => 'add_line', 'item_id' => $extra->id, 'quantity' => '4', 'discount_bp' => 200],
            ['type' => 'close_short', 'order_line_id' => $first->id, 'quantity' => '3'],
        ]);
        app(TransitionOrderAmendment::class)->submit($amendment, $this->rep);

        $order->refresh();
        $first->refresh();
        $added = $order->lines()->where('item_id', $extra->id)->sole();
        $this->assertSame('3.000', (string) $first->cancelled_qty);
        $this->assertSame('10.000', (string) $first->quantity, 'The ordered quantity stays; the cancelled part is tracked apart.');
        $this->assertSame($amendment->id, $added->added_by_amendment_id);
        $this->assertSame(2, (int) $added->position);
        $this->assertSame($order->gross_minor, (int) $order->lines()->sum('line_gross_minor'));
        $this->assertSame(7.0, (float) StockReservation::query()->where('order_line_id', $first->id)->where('status', ReservationStatus::Active)->sum('quantity'));
        $this->assertSame(4.0, (float) StockReservation::query()->where('order_line_id', $added->id)->where('status', ReservationStatus::Active)->sum('quantity'));

        $removal = $this->amend($order->fresh(), [['type' => 'remove_line', 'order_line_id' => $added->id]]);
        app(TransitionOrderAmendment::class)->submit($removal, $this->rep);
        $added->refresh();
        $this->assertSame('4.000', (string) $added->cancelled_qty);
        $this->assertSame(0, $added->line_gross_minor);
        $this->assertSame(0.0, (float) StockReservation::query()->where('order_line_id', $added->id)->where('status', ReservationStatus::Active)->sum('quantity'));
        $this->assertSame(3, $order->fresh()->revision);

        $manage = app(ManageOrderAmendment::class);
        $last = $manage->start($order->fresh(), $this->rep, 'Everything');
        $this->expectError('would_empty_order', fn () => $manage->addChange($last, ['type' => 'remove_line', 'order_line_id' => $first->id]));
        $this->expectError('close_short_over_open', fn () => $manage->addChange($last, ['type' => 'close_short', 'order_line_id' => $first->id, 'quantity' => '8']));
    }

    public function test_the_total_cannot_drop_below_what_was_paid(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $line = $order->lines()->sole();
        $order->forceFill(['paid_minor' => $order->gross_minor])->save();
        $amendment = $this->amend($order->fresh(), [['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '5']]);

        $this->expectError('total_below_paid', fn () => app(TransitionOrderAmendment::class)->submit($amendment, $this->rep));
        $this->assertSame('10.000', (string) $line->fresh()->quantity);
        $this->assertSame(1, $order->fresh()->revision);
    }

    public function test_only_started_orders_can_be_amended_and_one_amendment_stays_open(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $manage = app(ManageOrderAmendment::class);
        $manage->start($order, $this->rep, 'First');
        $this->expectError('open_amendment_exists', fn () => $manage->start($order->fresh(), $this->rep, 'Second'));
        $this->expectError('reason_required', fn () => $manage->start(Order::query()->findOrFail($order->id), $this->manager, ''));

        $order->forceFill(['status' => OrderStatus::Delivered])->save();
        $this->expectError('order_not_amendable', fn () => $manage->start($order->fresh(), $this->rep, 'Late'));
    }

    public function test_the_amendment_screen_follows_the_permissions(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $line = $order->lines()->sole();
        $this->actingAs(User::factory()->sales()->create())->get(route('orders.amendments', $order))->assertForbidden();

        Livewire::actingAs($this->rep)->test(Amendments::class, ['order' => $order])
            ->set('reason', '')->call('start')
            ->set('reason', 'Customer needs more')->call('start')
            ->assertSeeHtml('data-open-amendment')
            ->set('type', 'qty_change')->set('lineId', $line->id)->set('quantity', '12')->call('addChange')
            ->assertSee('10 → 12')
            ->call('submit')
            ->assertSee(__('order_amendments.status.applied'))
            ->assertSeeHtml('data-amendment-history');

        $this->assertSame('12.000', (string) $line->fresh()->quantity);
        $this->actingAs($this->rep)->get(route('orders.show', $order))->assertOk()->assertSeeHtml('data-action="amend"');

        Livewire::actingAs($this->manager)->test(Amendments::class, ['order' => $order])->assertSee('#1');
        Livewire::actingAs(User::factory()->finance()->create())->test(Amendments::class, ['order' => $order])->assertSee(__('order_amendments.cannot_amend'));
    }

    public function test_a_binding_agreement_gets_an_addendum_draft_and_the_order_waits_for_it(): void
    {
        $this->setUpCompany();
        $this->seedAgreementTemplates();
        $customer = $this->agreementReadyCustomer($this->rep);
        $order = $this->simpleOrder($this->rep, '10', '100', $customer);
        $agreement = app(GenerateAgreement::class)->handle(AgreementKind::Order, $customer, $order, $this->rep);
        $agreement->forceFill(['status' => AgreementStatus::Signed])->save();
        $line = $order->lines()->sole();

        $amendment = $this->amend($order->fresh(), [['type' => 'qty_change', 'order_line_id' => $line->id, 'quantity' => '12']]);
        $applied = app(TransitionOrderAmendment::class)->submit($amendment, $this->rep);

        $addendum = $applied->addendum;
        $this->assertNotNull($addendum);
        $this->assertSame([AgreementKind::Addendum, $agreement->id], [$addendum->kind, $addendum->parent_agreement_id]);
        $this->assertTrue($order->fresh()->addendum_pending);

        $this->actingAs($this->rep)->get(route('orders.amendments', $order))->assertOk()->assertSeeHtml('data-addendum-pending')->assertSeeHtml('data-addendum-link');

        app(TransitionAgreement::class)->handle($addendum, AgreementStatus::Cancelled, $this->manager, 'not needed');
        $this->assertFalse($order->fresh()->addendum_pending);
    }
}
