<?php

namespace Tests\Feature\Orders;

use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\StartPackaging;
use App\Actions\Orders\UpdateOrderDetails;
use App\Actions\Orders\WaiveAgreement;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\DepartmentRole;
use App\Enums\HoldReason;
use App\Enums\OrderStatus;
use App\Enums\ReservationStatus;
use App\Livewire\Orders\Show;
use App\Models\StockReservation;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 6.1 confirmed → in_progress (AC-6) and cancellation (AC-9 without payments yet).
 */
class FulfilmentAndCancelTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    /** AC-6 */
    public function test_fulfilment_waits_for_the_agreement_unless_waived(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        app(ConfirmOrder::class)->handle($order, $rep);

        try {
            app(StartPackaging::class)->handle($order, $rep);
            $this->fail('Start must wait for the agreement.');
        } catch (OrderActionException $exception) {
            $this->assertSame('on_hold', $exception->key);
            $this->assertStringContainsString(HoldReason::AgreementUnsigned->label(), $exception->getMessage());
        }

        $this->assertFalse($rep->can('waiveAgreement', $order->fresh()));
        $manager = User::factory()->manager()->create();
        app(WaiveAgreement::class)->handle($order, $manager, 'Repeat customer, signed paper offer');
        app(StartPackaging::class)->handle($order->fresh(), $rep);

        $order->refresh();
        $this->assertSame(OrderStatus::Packing, $order->status);
        $this->assertFalse($order->agreement_required);
        $this->assertSame([], $order->activeHolds());
    }

    public function test_fulfilment_starts_without_hold_when_the_setting_is_off(): void
    {
        config(['orders.block_fulfilment_without_agreement' => false]);
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        app(ConfirmOrder::class)->handle($order, $rep);

        // Spec 06 section 25: packaging is warehouse work; sales cannot start it.
        Livewire::actingAs($rep)->test(Show::class, ['order' => $order])->call('startPackaging')->assertForbidden();
        Livewire::actingAs(User::factory()->stockManager()->create())->test(Show::class, ['order' => $order])->call('startPackaging');

        $this->assertSame(OrderStatus::Packing, $order->fresh()->status);
    }

    /** AC-9 (payments arrive with job 4) */
    public function test_cancelling_releases_reservations_and_cancels_the_unsigned_agreement(): void
    {
        $this->setUpCompany();
        $this->seedAgreementTemplates();
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep, '5', '50', $this->agreementReadyCustomer($rep));
        app(ConfirmOrder::class)->handle($order, $rep);
        $accountant = User::factory()->accountant()->create();
        $agreement = app(GenerateAgreement::class)->handle(AgreementKind::Order, $order->customer, $order->fresh(), $accountant);

        $this->assertTrue($rep->can('cancel', $order->fresh()) === false, 'A sales owner may cancel only new orders.');
        $head = User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create();
        $order->forceFill(['owner_id' => $head->id])->save();
        $this->assertTrue($head->can('cancel', $order->fresh()));

        Livewire::actingAs($head)->test(Show::class, ['order' => $order])
            ->call('openPanel', 'cancel')
            ->call('cancel')
            ->assertHasErrors('reason')
            ->set('reason', 'Customer changed project')
            ->call('cancel');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Customer changed project', $order->cancel_reason);
        $this->assertSame(ReservationStatus::Released, StockReservation::query()->sole()->status);
        $this->assertSame(AgreementStatus::Cancelled, $agreement->fresh()->status);
        $this->assertSame('order_cancelled', $agreement->fresh()->cancel_reason);
    }

    public function test_cancelling_is_refused_while_payments_are_allocated(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $order->forceFill(['paid_minor' => 1000])->save();

        $this->expectExceptionObject(new OrderActionException('cancel_has_payments'));
        app(CancelOrder::class)->handle($order, $rep, 'test');
    }

    public function test_delivery_details_can_change_only_while_new(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);

        app(UpdateOrderDetails::class)->handle($order, ['delivery_address' => 'Chilonzor 5', 'promised_delivery_on' => '2026-11-20']);
        $this->assertSame('Chilonzor 5', $order->fresh()->delivery_address);

        // Spec 06 section 31: details stay editable until packaging starts.
        $order->forceFill(['started_at' => now()])->save();
        $this->expectExceptionObject(new OrderActionException('details_locked'));
        app(UpdateOrderDetails::class)->handle($order, ['delivery_address' => 'Other']);
    }
}
