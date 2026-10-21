<?php

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\RecordPayment;
use App\Actions\Orders\ConfirmOrder;
use App\Livewire\Accounting\Periods;
use App\Livewire\Settings\Accounting;
use App\Models\CompanyBankAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use App\Services\Accounting\Receivables;
use App\Support\BusinessSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Accounting screens and scoping (Spec 06 4.2, 10.2): aging per currency (AC-31), payment
 * visibility and hidden bank data for sales, settings and periods screens, navigation.
 */
class AccountingScreensTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $accountant;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Tashkent'));
        $this->accountant = User::factory()->accountant()->create();
        $this->rep = User::factory()->sales()->create();
        CompanyBankAccount::query()->create(['bank_name' => 'Kapitalbank', 'mfo' => '01158', 'account_number' => '20208000900123456001', 'currency' => 'UZS', 'is_default' => true, 'is_active' => true]);
    }

    /** AC-31 */
    public function test_aging_buckets_per_currency(): void
    {
        $order = $this->simpleOrder($this->rep, '10', '100');
        $order->forceFill(['prepayment_bp' => 5000, 'promised_delivery_on' => '2026-10-18', 'payment_terms_days' => 0])->save();
        // Spec 06 section 31: orders start on creation; terms set afterwards regenerate the schedule.
        app(PaymentScheduleGenerator::class)->generate($order->fresh(), $this->rep);
        app(OrderPayments::class)->recompute($order->fresh());
        $payment = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => 1_000_000, 'currency' => 'UZS', 'paid_on' => '2026-10-18', 'method' => 'cash', 'payer_name' => 'Buyer'], $this->accountant);
        app(AllocatePayment::class)->handle($payment, $order, 200_000, $this->accountant);

        $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'Asia/Tashkent'));
        $rows = app(Receivables::class)->aging($this->accountant);

        $this->assertCount(1, $rows);
        $this->assertSame('UZS', $rows[0]['currency']);
        // FIFO by due date: the balance (due 18.10) takes the 200 000 first → 360 000 left, 43 days late;
        // the prepayment (due 23.10) is fully open → 560 000, 38 days late. Both fall in 31–60.
        $this->assertSame(['current' => 0, 'd1_30' => 0, 'd31_60' => 920_000, 'd61_90' => 0, 'd90_plus' => 0], $rows[0]['buckets']);
        $this->assertSame(920_000, $rows[0]['total']);
        $this->assertSame(800_000, $rows[0]['advance'], 'Unallocated remainder shown as advance.');
        $this->assertSame(920_000, app(Receivables::class)->totals($rows)['UZS']['total']);

        $this->actingAs($this->accountant)->get(route('receivables.index'))->assertOk()->assertSee('data-receivables', false);
        $this->assertSame([], app(Receivables::class)->aging(User::factory()->sales()->create()), 'Another rep sees nothing.');
    }

    public function test_sales_see_their_payments_without_bank_data(): void
    {
        $order = $this->simpleOrder($this->rep, '1', '10');
        app(ConfirmOrder::class)->handle($order, $this->rep);
        $payment = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => 100_000, 'currency' => 'UZS', 'paid_on' => '2026-10-18', 'method' => 'bank_transfer', 'company_bank_account_id' => CompanyBankAccount::query()->value('id'), 'payer_name' => 'Buyer', 'reference_number' => 'R1'], $this->accountant);
        app(AllocatePayment::class)->handle($payment, $order, 100_000, $this->accountant);

        $this->actingAs($this->rep)->get(route('payments.show', $payment))->assertOk()->assertDontSee('20208000900123456001');
        $this->actingAs($this->accountant)->get(route('payments.show', $payment))->assertOk()->assertSee('20208000900123456001');
        $this->actingAs(User::factory()->sales()->create())->get(route('payments.show', $payment))->assertForbidden();
        $this->actingAs($this->rep)->get(route('payments.index'))->assertOk()->assertSee($payment->number);
        $this->assertFalse($this->rep->can('allocate', $payment));
        $this->assertSame(0, Payment::query()->visibleTo(User::factory()->sales()->create())->count());
    }

    public function test_settings_and_periods_screens(): void
    {
        $finance = User::factory()->finance()->create();

        Livewire::actingAs($finance)->test(Accounting::class)
            ->set('amounts.accounting__payment_approval_threshold_minor.UZS', '50000000.00')
            ->set('numbers.accounting__hold_overdue_days', '15')
            ->set('codeModes.class_code', 'block')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(5_000_000_000, BusinessSettings::array('accounting.payment_approval_threshold_minor')['UZS']);
        $this->assertSame(15, BusinessSettings::int('accounting.hold_overdue_days'));
        $this->assertSame('block', BusinessSettings::array('accounting.invoice_code_validation')['class_code']);

        Livewire::actingAs($this->accountant)->test(Accounting::class)->call('save')->assertForbidden();
        $this->actingAs($this->rep)->get(route('settings.accounting'))->assertForbidden();

        Livewire::actingAs($finance)->test(Periods::class)
            ->set('rate.currency', 'USD')
            ->set('rate.rate', '12850.5')
            ->call('saveRate')
            ->assertHasNoErrors()
            ->call('lock', '2026-09')
            ->assertSee(__('accounting.period_status.locked'));

        $this->actingAs($this->accountant)->get(route('accounting.periods'))->assertOk();
        $this->actingAs($this->accountant)->get(route('dashboard'))->assertSee(__('nav.payments'))->assertSee(__('nav.invoices'))->assertSee(__('nav.receivables'));
        $this->actingAs($this->rep)->get(route('dashboard'))->assertSee(__('nav.payments'))->assertDontSee(__('nav.accounting_periods'));
    }
}
