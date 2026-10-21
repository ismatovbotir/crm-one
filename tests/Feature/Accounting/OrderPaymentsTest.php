<?php

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\DecidePayment;
use App\Actions\Accounting\RecordOrderPayments;
use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Orders\OverrideHold;
use App\Enums\HoldReason;
use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Orders\Index as OrdersIndex;
use App\Livewire\Payments\ForOrders;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ShippedUnpaid;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 section 29: "payment for orders" (partner → unpaid orders → amount per order), the
 * payment signal for the warehouse, release before payment and the "shipped unpaid" follow-up.
 */
class OrderPaymentsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $accountant;

    private User $rep;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Tashkent'));
        $this->accountant = User::factory()->accountant()->create();
        $this->rep = User::factory()->sales()->create();
        $this->customer = Customer::factory()->create(['owner_id' => $this->rep->id]);
        CompanyBankAccount::query()->create(['bank_name' => 'Kapitalbank', 'mfo' => '01158', 'account_number' => '20208000900123456001', 'currency' => 'UZS', 'is_default' => true, 'is_active' => true]);
    }

    /** 10 × 1 000.00 + 12% VAT = 11 200.00 UZS, confirmed with 50% prepayment. */
    private function confirmedOrder(): Order
    {
        $offer = $this->draftOffer($this->rep, $this->customer);
        $this->addLine($offer, $this->pricedItem('1000.00', ['name' => 'Pump']), '10');
        app(SendOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email);
        app(AcceptOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email, 'ok');
        $order = Order::query()->where('offer_id', $offer->id)->firstOrFail();
        $order->forceFill(['prepayment_bp' => 5000])->save();
        // Spec 06 section 31: orders start on creation; terms set afterwards regenerate the schedule.
        app(PaymentScheduleGenerator::class)->generate($order->fresh(), $this->rep);
        app(OrderPayments::class)->recompute($order->fresh());

        return $order->fresh();
    }

    public function test_one_payment_per_ticked_order_allocated_at_once_and_never_above_outstanding(): void
    {
        $first = $this->confirmedOrder();
        $second = $this->confirmedOrder();

        $payments = app(RecordOrderPayments::class)->handle([
            'customer_id' => $this->customer->id, 'paid_on' => '2026-10-18', 'method' => 'cash',
            'orders' => [$first->id => 1_120_000, $second->id => 300_000],
        ], $this->accountant);

        $this->assertCount(2, $payments);
        $this->assertSame(OrderPaymentStatus::Paid, $first->fresh()->payment_status);
        $this->assertSame(OrderPaymentStatus::PartiallyPaid, $second->fresh()->payment_status);
        $this->assertSame([$first->id, $second->id], Payment::query()->orderBy('id')->pluck('planned_order_id')->all());
        $this->assertSame('paid', $first->fresh()->paymentSignal()['state']);
        $this->assertSame('partial', $second->fresh()->paymentSignal()['state']);

        try {
            app(RecordOrderPayments::class)->handle(['customer_id' => $this->customer->id, 'paid_on' => '2026-10-18', 'method' => 'cash', 'orders' => [$second->id => 900_000]], $this->accountant);
            $this->fail('No advances: never above the outstanding amount.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('exceeds_order', $exception->key);
        }

        $this->expectException(AccountingActionException::class);
        app(RecordOrderPayments::class)->handle(['customer_id' => $this->customer->id, 'paid_on' => '2026-10-18', 'method' => 'cash', 'orders' => []], $this->accountant);
    }

    public function test_payment_above_the_approval_threshold_is_allocated_when_approved(): void
    {
        $order = $this->confirmedOrder();
        Setting::putMany(['accounting.payment_approval_threshold_minor' => (string) json_encode(['UZS' => 100_000])]);

        [$payment] = app(RecordOrderPayments::class)->handle(['customer_id' => $this->customer->id, 'paid_on' => '2026-10-18', 'method' => 'cash', 'orders' => [$order->id => 560_000]], $this->accountant);
        $this->assertSame(PaymentStatus::PendingApproval, $payment->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status, 'Pending payments never count.');

        app(DecidePayment::class)->approve($payment, User::factory()->finance()->create());
        $this->assertSame(560_000, (int) $order->fresh()->paid_minor);
    }

    /**
     * DBA review M-5: two payments waiting for approval, both planned for the whole order. The
     * second approval sees the first allocation (order locked, then read) and allocates only what
     * is still open; the approval itself succeeds and the order is never over-allocated.
     */
    public function test_second_approval_for_the_same_order_cannot_over_allocate(): void
    {
        $order = $this->confirmedOrder();
        Setting::putMany(['accounting.payment_approval_threshold_minor' => (string) json_encode(['UZS' => 100_000])]);
        $record = app(RecordOrderPayments::class);
        [$first] = $record->handle(['customer_id' => $this->customer->id, 'paid_on' => '2026-10-18', 'method' => 'cash', 'orders' => [$order->id => 1_120_000]], $this->accountant);
        [$second] = $record->handle(['customer_id' => $this->customer->id, 'paid_on' => '2026-10-18', 'method' => 'cash', 'orders' => [$order->id => 1_120_000]], $this->accountant, null, true);
        $finance = User::factory()->finance()->create();

        app(DecidePayment::class)->approve($first, $finance);
        $approved = app(DecidePayment::class)->approve($second, $finance);

        $this->assertSame(PaymentStatus::Recorded, $approved->status, 'The approval itself never fails.');
        $this->assertSame(1_120_000, (int) $order->fresh()->paid_minor);
        $this->assertSame(1_120_000, (int) $order->allocations()->sum('order_amount_minor'), 'Allocated exactly the order total.');
        $this->assertSame(1_120_000, $second->fresh()->unallocatedMinor(), 'The second payment stays unallocated for the accountant.');
    }

    public function test_form_lists_unpaid_orders_prefills_outstanding_and_records(): void
    {
        $order = $this->confirmedOrder();

        Livewire::actingAs($this->accountant)->test(ForOrders::class)
            ->call('selectCustomer', $this->customer->id)
            ->assertSee($order->number)
            ->set('selected.'.$order->id, true)
            ->assertSet('amounts.'.$order->id, '11200.00')
            ->set('amounts.'.$order->id, '5600')
            ->set('method', 'cash')
            ->call('save')
            ->assertRedirect(route('payments.index'));

        $this->assertSame(OrderPaymentStatus::PartiallyPaid, $order->fresh()->payment_status);
        $this->actingAs($this->rep)->get(route('payments.for-orders'))->assertForbidden();
    }

    public function test_release_before_payment_is_shown_to_the_warehouse_and_unpaid_shipping_is_followed_up(): void
    {
        Notification::fake();
        $order = $this->confirmedOrder();
        $this->assertSame('unpaid', $order->paymentSignal()['state']);

        $manager = User::factory()->manager()->create(['name' => 'Boss']);
        $order->forceFill(['hold_reasons' => [['reason' => HoldReason::PrepaymentMissing->value, 'set_at' => now()->toIso8601String()]], 'on_hold' => true])->save();
        app(OverrideHold::class)->handle($order->fresh(), HoldReason::PrepaymentMissing, $manager, 'Key client, pays on delivery');
        $signal = $order->fresh()->paymentSignal();
        $this->assertSame(['released', 'Boss'], [$signal['state'], $signal['released_by']]);

        $order->forceFill(['status' => 'delivered'])->save();
        app(Notifier::class)->shippedUnpaid($order->fresh(), $order->deliveries()->firstOrNew(['number' => 'DN-TEST']), $manager);
        Notification::assertSentTo($this->accountant, ShippedUnpaid::class);
        Notification::assertSentTo($this->rep, ShippedUnpaid::class);

        Livewire::actingAs($manager)->test(OrdersIndex::class)->set('queue', 'shipped_unpaid')->assertSee($order->number);
    }
}
