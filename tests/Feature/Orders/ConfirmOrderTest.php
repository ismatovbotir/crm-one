<?php

namespace Tests\Feature\Orders;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\OverrideHold;
use App\Enums\HoldReason;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Orders\Show;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\StockAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 6.1 new → confirmed, 6.2 holds, BR-OR.3/5/6/7, AC-2..AC-4.
 */
class ConfirmOrderTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_confirming_reserves_stock_and_is_logged(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep, '10', '100');

        app(ConfirmOrder::class)->handle($order, $rep);
        $order->refresh();

        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($rep->id, $order->confirmed_by);
        $reservation = StockReservation::query()->firstOrFail();
        $this->assertSame(['10.000', ReservationStatus::Active], [(string) $reservation->quantity, $reservation->status]);
        $this->assertSame('90.000', app(StockAvailability::class)->forItem($reservation->item_id));
        $this->assertTrue($order->transitions()->where('to_status', 'confirmed')->exists());
    }

    /** AC-2 */
    /** Spec 06 section 31: a blocked customer keeps the new order in `new` (it cannot start). */
    public function test_a_customer_deleted_in_1c_blocks_confirmation(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $this->addLine($offer, $this->pricedItem('1000.00', ['name' => 'Pump'], '100'), '10');
        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);
        $offer->customer->forceFill(['external_deleted_at' => now()])->saveQuietly();
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'mail');
        $order = Order::query()->where('offer_id', $offer->id)->firstOrFail();

        $this->assertSame(OrderStatus::New, $order->fresh()->status, 'It could not start automatically.');

        try {
            app(ConfirmOrder::class)->handle($order, $rep);
            $this->fail('Starting must be refused.');
        } catch (OrderActionException $exception) {
            $this->assertSame('customer_blocked', $exception->key);
            $this->assertSame(__('orders.errors.customer_blocked'), $exception->getMessage());
        }

        $this->assertSame(OrderStatus::New, $order->fresh()->status);
    }

    /** AC-3, Spec 06 section 31: the order starts on creation; shortages become backorder lines. */
    public function test_shortage_needs_acknowledgement_and_reserves_only_what_is_available(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep, '10', '4');

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertTrue($order->shortage_acknowledged);
        $this->assertTrue($order->lines->first()->backorder);
        $this->assertSame('4.000', (string) StockReservation::query()->sole()->quantity);
        $this->assertSame('shortage_acknowledged', $order->transitions()->latest('id')->value('reason'));
    }

    public function test_shortage_blocks_when_the_setting_says_so(): void
    {
        config(['orders.block_confirm_on_shortage' => true]);
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep, '10', '4');

        $this->expectExceptionObject(new OrderActionException('shortage_blocked', ['lines' => 'Pump (4 / 10)']));
        app(ConfirmOrder::class)->handle($order, $rep, true);
    }

    /** AC-4 */
    public function test_credit_limit_hold_refuses_confirmation_until_finance_releases_it(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create(['currency' => 'UZS', 'credit_limit_minor' => 1_000_000_00, 'credit_limit_currency' => 'UZS']);
        $earlier = $this->simpleOrder($rep, '800', '10000', $customer);
        app(ConfirmOrder::class)->handle($earlier, $rep);
        $order = $this->simpleOrder($rep, '200', '10000', $customer);

        try {
            app(ConfirmOrder::class)->handle($order, $rep);
            $this->fail('The credit limit must hold the order.');
        } catch (OrderActionException $exception) {
            $this->assertSame('on_hold', $exception->key);
        }

        $order->refresh();
        $this->assertTrue($order->on_hold);
        $this->assertSame([HoldReason::CreditLimit], $order->activeHolds());
        $this->assertFalse($rep->can('overrideHold', [$order, HoldReason::CreditLimit]));

        $finance = User::factory()->finance()->create();
        $this->assertTrue($finance->can('overrideHold', [$order, HoldReason::CreditLimit]));
        app(OverrideHold::class)->handle($order, HoldReason::CreditLimit, $finance, 'Director approved');
        app(ConfirmOrder::class)->handle($order->fresh(), $rep);

        $order->refresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame([HoldReason::AgreementUnsigned], $order->activeHolds(), 'Only the fulfilment hold for the missing agreement remains.');
        $this->assertSame('Director approved', $order->hold_reasons[0]['override_reason']);

        Livewire::actingAs($rep)->test(Show::class, ['order' => $order])
            ->assertSee(__('orders.show.released_by', ['user' => $finance->name, 'reason' => 'Director approved']));
    }

    public function test_a_manager_cannot_release_the_credit_hold_of_their_own_order(): void
    {
        $manager = User::factory()->manager()->create();
        $order = Order::factory()->ownedBy($manager)->create();
        $other = Order::factory()->create();

        $this->assertFalse($manager->can('overrideHold', [$order, HoldReason::CreditLimit]));
        $this->assertTrue($manager->can('overrideHold', [$other, HoldReason::CreditLimit]));
        $this->assertFalse($manager->can('overrideHold', [$other, HoldReason::Manual]));
    }

    public function test_a_manual_customer_hold_blocks_confirmation(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $order->customer->forceFill(['manual_hold' => true, 'manual_hold_reason' => 'Disputed invoices'])->saveQuietly();

        $this->expectExceptionObject(new OrderActionException('on_hold', ['reasons' => HoldReason::Manual->label()]));
        app(ConfirmOrder::class)->handle($order, $rep);
    }

    public function test_reservations_of_one_order_reduce_availability_for_the_next(): void
    {
        $rep = User::factory()->sales()->create();
        $first = $this->simpleOrder($rep, '6', '10');
        app(ConfirmOrder::class)->handle($first, $rep);
        $itemId = $first->lines->first()->item_id;

        $this->assertSame('4.000', app(StockAvailability::class)->forItem($itemId));

        StockReservation::query()->update(['status' => ReservationStatus::MirroredIn1c]);
        $this->assertSame('10.000', app(StockAvailability::class)->forItem($itemId), 'Rows mirrored in 1C are inside item_stocks.reserved already (DEC-8).');
    }
}
