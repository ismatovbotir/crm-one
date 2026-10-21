<?php

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\DeallocatePayment;
use App\Actions\Accounting\DecidePayment;
use App\Actions\Accounting\ManagePeriods;
use App\Actions\Accounting\RecordPayment;
use App\Actions\Accounting\RecordRefund;
use App\Actions\Accounting\UpdatePaymentTerms;
use App\Actions\Accounting\VoidPayment;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\StartPackaging;
use App\Actions\Orders\WaiveAgreement;
use App\Enums\EntryType;
use App\Enums\HoldReason;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Payments\Create;
use App\Livewire\Payments\Show;
use App\Models\AccountingEntry;
use App\Models\AccountingPeriod;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentScheduleItem;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use App\Services\Orders\Exceptions\OrderActionException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 payments: AC-5, AC-23..AC-29, SoD-1/3/4/7, DEC-3, BR-AC.2/5/7/8/9/13.
 */
class PaymentWorkflowTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $accountant;

    private User $finance;

    private User $rep;

    private CompanyBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Tashkent'));
        $this->accountant = User::factory()->accountant()->create();
        $this->finance = User::factory()->finance()->create();
        $this->rep = User::factory()->sales()->create();
        $this->bank = CompanyBankAccount::query()->create(['bank_name' => 'Kapitalbank', 'mfo' => '01158', 'account_number' => '20208000900123456001', 'currency' => 'UZS', 'is_default' => true, 'is_active' => true]);
    }

    /** A confirmed order of the rep's customer: 10 × 1 000.00 + 12% VAT = 11 200.00 UZS. */
    private function confirmedOrder(?Customer $customer = null, ?int $prepaymentBp = null): Order
    {
        $order = $this->simpleOrder($this->rep, '10', '100', $customer);

        if ($prepaymentBp !== null) {
            $order->forceFill(['prepayment_bp' => $prepaymentBp])->save();
            // Spec 06 section 31: orders start on creation; terms set afterwards regenerate the schedule.
            app(PaymentScheduleGenerator::class)->generate($order->fresh(), $this->rep);
            app(OrderPayments::class)->recompute($order->fresh());
        }

        return $order->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(Customer $customer, int $amount, array $overrides = [], ?User $by = null): Payment
    {
        return app(RecordPayment::class)->handle([
            'customer_id' => $customer->id, 'amount_minor' => $amount, 'currency' => 'UZS', 'paid_on' => '2026-10-18', 'method' => 'bank_transfer',
            'company_bank_account_id' => $this->bank->id, 'payer_name' => 'Buyer LLC', 'reference_number' => 'PP-'.$amount, ...$overrides,
        ], $by ?? $this->accountant);
    }

    /** AC-23, SoD-1 */
    public function test_sales_cannot_record_payments(): void
    {
        $this->actingAs($this->rep)->get(route('payments.create'))->assertForbidden();
        Livewire::actingAs($this->rep)->test(Create::class)->assertForbidden();
        $this->assertSame(0, Payment::query()->count());
        $this->assertFalse($this->rep->can('create', Payment::class));
        $this->assertFalse(User::factory()->admin()->create()->can('create', Payment::class), 'Admin has no business rights.');
    }

    /** AC-24, SoD-3/4 */
    public function test_threshold_approval_ledger_and_outbox(): void
    {
        $order = $this->confirmedOrder();
        $customer = $order->customer;

        $small = $this->record($customer, 5_000_000_000);
        $this->assertSame(PaymentStatus::Recorded, $small->status);
        $this->assertMatchesRegularExpression('/^PM-2026-\d{5}$/', $small->number);
        $this->assertTrue(AccountingEntry::query()->where('payment_id', $small->id)->where('entry_type', EntryType::PaymentRecorded)->exists());
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'payment.recorded')->where('aggregate_id', $small->id)->exists());

        try {
            $this->record($customer, 15_000_000_000);
            $this->fail('A proof is required above the threshold.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('proof_required', $exception->key);
        }

        Storage::fake('local');
        $large = app(RecordPayment::class)->handle([
            'customer_id' => $customer->id, 'amount_minor' => 15_000_000_000, 'currency' => 'UZS', 'paid_on' => '2026-10-18', 'method' => 'bank_transfer',
            'company_bank_account_id' => $this->bank->id, 'payer_name' => 'Buyer LLC', 'reference_number' => 'PP-LARGE',
        ], $this->accountant, UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\n%%EOF"));
        $this->assertNotNull($large->proof_attachment_id);
        $this->assertSame(PaymentStatus::PendingApproval, $large->status);
        $this->assertFalse($large->counts());
        $this->assertFalse(OnecOutboxEvent::query()->where('aggregate_id', $large->id)->where('aggregate_type', 'payment')->exists());

        try {
            app(DecidePayment::class)->approve($large, $this->accountant);
            $this->fail('The recorder may not approve.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('self_approval', $exception->key);
        }

        $this->assertFalse($this->accountant->can('approve', $large->fresh()));
        app(DecidePayment::class)->approve($large->fresh(), $this->finance);
        $this->assertSame(PaymentStatus::Recorded, $large->fresh()->status);
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'payment.recorded')->where('aggregate_id', $large->id)->exists());
    }

    /** AC-25, BR-AC.7/9 */
    public function test_allocation_to_several_orders_of_the_same_customer_only(): void
    {
        $orderA = $this->confirmedOrder();
        $customer = $orderA->customer;
        $orderB = $this->confirmedOrder($customer);
        $foreign = $this->confirmedOrder();
        $payment = $this->record($customer, 1_500_000);

        app(AllocatePayment::class)->handle($payment, $orderA, 1_000_000, $this->accountant);
        app(AllocatePayment::class)->handle($payment->fresh(), $orderB, 300_000, $this->accountant);
        $payment->refresh();

        $this->assertSame(1_300_000, $payment->allocated_minor);
        $this->assertSame(200_000, $payment->unallocatedMinor());
        $this->assertSame([1_000_000, OrderPaymentStatus::PartiallyPaid], [$orderA->fresh()->paid_minor, $orderA->fresh()->payment_status]);

        try {
            app(AllocatePayment::class)->handle($payment, $foreign, 100_000, $this->accountant);
            $this->fail('Another customer’s order must be refused.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('customer_mismatch', $exception->key);
        }

        // Tampered Livewire payload: the foreign order id is re-checked server-side.
        Livewire::actingAs($this->accountant)->test(Show::class, ['payment' => $payment])
            ->call('openPanel', 'allocate')
            ->set('orderId', (string) $foreign->id)
            ->set('amount', '1000.00')
            ->call('allocate')
            ->assertSee(__('accounting.errors.customer_mismatch'));

        $this->expectExceptionObject(new AccountingActionException('exceeds_payment', ['amount' => $payment->money(200_000)->format()]));
        app(AllocatePayment::class)->handle($payment, $orderB, 300_000, $this->accountant);
    }

    /** AC-26 */
    public function test_usd_payment_allocated_to_a_uzs_order_uses_the_rate_half_up(): void
    {
        $order = $this->confirmedOrder();
        CompanyBankAccount::query()->create(['bank_name' => 'Kapitalbank', 'mfo' => '01158', 'account_number' => '20208840900123456002', 'currency' => 'USD', 'is_default' => true, 'is_active' => true]);
        $usd = CompanyBankAccount::query()->where('currency', 'USD')->sole();
        $payment = $this->record($order->customer, 10_000, ['currency' => 'USD', 'company_bank_account_id' => $usd->id]);

        try {
            app(AllocatePayment::class)->handle($payment, $order, 1_000, $this->accountant);
            $this->fail('A rate is required.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('fx_rate_required', $exception->key);
        }

        app(ManagePeriods::class)->saveRate('USD', '2026-10-18', '12850.50', $this->finance);
        $allocation = app(AllocatePayment::class)->handle($payment, $order, 87, $this->accountant);

        $this->assertSame('12850.50000000', (string) $allocation->fx_rate);
        $this->assertSame(1_117_994, $allocation->order_amount_minor, '0.87 USD × 12 850.50 = 11 179.935 → 11 179.94 UZS (half up).');
        $this->assertSame('cbu_manual', $allocation->fx_source);
    }

    /** AC-27, DEC-3 */
    public function test_payments_are_immutable_and_voids_need_a_second_person(): void
    {
        $order = $this->confirmedOrder();
        $payment = $this->record($order->customer, 500_000);
        app(AllocatePayment::class)->handle($payment, $order, 500_000, $this->accountant);

        try {
            $payment->fresh()->update(['amount_minor' => 1]);
            $this->fail('The amount must be immutable.');
        } catch (LogicException) {
            $this->assertSame(500_000, $payment->fresh()->amount_minor);
        }

        try {
            $payment->fresh()->delete();
            $this->fail('Payments are never deleted.');
        } catch (LogicException) {
            $this->assertTrue(Payment::query()->whereKey($payment->id)->exists());
        }

        app(VoidPayment::class)->request($payment, $this->accountant, 'Wrong customer');

        try {
            app(VoidPayment::class)->approve($payment, $this->accountant);
            $this->fail('The requester may not approve.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('self_approval', $exception->key);
        }

        app(VoidPayment::class)->approve($payment, $this->finance);
        $payment->refresh();
        $order->refresh();

        $this->assertSame(PaymentStatus::Voided, $payment->status);
        $this->assertSame([0, 0], [$payment->allocated_minor, $order->paid_minor]);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertSame(2, $payment->allocations()->count(), 'Original row plus its reversal.');
        $this->assertTrue(AccountingEntry::query()->where('payment_id', $payment->id)->where('entry_type', EntryType::PaymentVoided)->where('amount_minor', -500_000)->exists());
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'payment.voided')->where('aggregate_id', $payment->id)->exists());
    }

    /** AC-28 */
    public function test_refunds_come_from_the_unallocated_balance_and_need_approval(): void
    {
        $order = $this->confirmedOrder();
        $payment = $this->record($order->customer, 1_500_000);
        app(AllocatePayment::class)->handle($payment, $order, 1_120_000, $this->accountant);

        try {
            app(RecordRefund::class)->handle($payment->fresh(), ['amount_minor' => 400_000, 'paid_on' => '2026-10-18', 'method' => 'bank_transfer'], $this->accountant);
            $this->fail('Refund above the unallocated balance must fail.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('refund_exceeds_balance', $exception->key);
        }

        $refund = app(RecordRefund::class)->handle($payment->fresh(), ['amount_minor' => 200_000, 'paid_on' => '2026-10-18', 'method' => 'bank_transfer'], $this->accountant);
        $this->assertSame([PaymentStatus::PendingApproval, $payment->id], [$refund->status, $refund->refund_of_payment_id]);
        $this->assertMatchesRegularExpression('/^RF-2026-\d{5}$/', $refund->number);

        app(DecidePayment::class)->approve($refund, $this->finance);
        $this->assertSame([200_000, 180_000], [$payment->fresh()->refunded_minor, $payment->fresh()->unallocatedMinor()]);
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'payment.refund_recorded')->exists());
    }

    /** AC-29, BR-AC.13 */
    public function test_locked_period_refuses_money_actions_dated_in_it(): void
    {
        $order = $this->confirmedOrder();
        app(ManagePeriods::class)->lock('2026-09', $this->finance);

        try {
            $this->record($order->customer, 100_000, ['paid_on' => '2026-09-30']);
            $this->fail('A payment dated in a locked period must be refused.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('period_locked', $exception->key);
        }

        $this->assertSame(PaymentStatus::Recorded, $this->record($order->customer, 100_000)->status, 'October is open.');

        try {
            app(ManagePeriods::class)->reopen(AccountingPeriod::query()->sole(), $this->finance, 'fix');
            $this->fail('The locker may not reopen.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('self_approval', $exception->key);
        }
    }

    /** BR-AC.5 */
    public function test_duplicate_guard_needs_confirmation(): void
    {
        $order = $this->confirmedOrder();
        $first = $this->record($order->customer, 300_000);

        try {
            $this->record($order->customer, 300_000);
            $this->fail('A duplicate must be refused without confirmation.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('possible_duplicate', $exception->key);
            $this->assertStringContainsString($first->number, $exception->getMessage());
        }

        $second = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => 300_000, 'currency' => 'UZS', 'paid_on' => '2026-10-18', 'method' => 'bank_transfer', 'company_bank_account_id' => $this->bank->id, 'payer_name' => 'Buyer', 'reference_number' => 'PP-300000'], $this->accountant, null, true);
        $this->assertNotSame($first->id, $second->id);
    }

    /** BR-AC.2 + AC-5 */
    public function test_prepayment_schedule_holds_fulfilment_until_covered(): void
    {
        $order = $this->confirmedOrder(null, 5000);
        app(WaiveAgreement::class)->handle($order, User::factory()->manager()->create(), 'Signed paper');

        $schedule = $order->scheduleItems()->get();
        $this->assertSame([560_000, 560_000], $schedule->pluck('amount_minor')->all(), '50% of 11 200.00 and the balance.');
        $this->assertSame('2026-10-23', $schedule->first()->due_on->toDateString());

        try {
            app(StartPackaging::class)->handle($order, $this->rep);
            $this->fail('Prepayment missing must hold the order.');
        } catch (OrderActionException $exception) {
            $this->assertStringContainsString(HoldReason::PrepaymentMissing->label(), $exception->getMessage());
        }

        $payment = $this->record($order->customer, 560_000);
        app(AllocatePayment::class)->handle($payment, $order, 560_000, $this->accountant);
        app(StartPackaging::class)->handle($order->fresh(), $this->rep);

        $this->assertSame(OrderStatus::Packing, $order->fresh()->status);
    }

    public function test_overdue_status_and_hold_of_new_orders(): void
    {
        $order = $this->confirmedOrder(null, 10000);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'Asia/Tashkent'));
        $this->artisan('accounting:nightly')->assertSuccessful();

        $this->assertSame(OrderPaymentStatus::Overdue, $order->fresh()->payment_status);

        $next = $this->simpleOrder($this->rep, '1', '100', $order->customer);
        $this->expectExceptionObject(new OrderActionException('on_hold', ['reasons' => HoldReason::Overdue->label()]));
        app(ConfirmOrder::class)->handle($next, $this->rep);
    }

    public function test_deallocation_and_payment_terms_regenerate_the_schedule(): void
    {
        $order = $this->confirmedOrder();
        $payment = $this->record($order->customer, 400_000);
        $allocation = app(AllocatePayment::class)->handle($payment, $order, 400_000, $this->accountant);

        app(DeallocatePayment::class)->handle($allocation, $this->accountant, 'Wrong order');
        $this->assertSame([0, 0], [$order->fresh()->paid_minor, $payment->fresh()->allocated_minor]);

        app(UpdatePaymentTerms::class)->handle($order, $this->accountant, 3000, 15);
        $this->assertSame([336_000, 784_000], $order->fresh()->scheduleItems()->pluck('amount_minor')->all(), '30% prepayment and the balance; superseded items stay in history.');
        $this->assertSame(3, PaymentScheduleItem::query()->where('order_id', $order->id)->count());

        $this->expectExceptionObject(new AccountingActionException('already_reversed'));
        app(DeallocatePayment::class)->handle($allocation, $this->accountant, 'again');
    }
}
