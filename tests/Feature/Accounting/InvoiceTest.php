<?php

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\CloseOrder;
use App\Actions\Accounting\IssueInvoice;
use App\Actions\Accounting\RecordPayment;
use App\Actions\Accounting\VoidInvoice;
use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Orders\RecordPowerOfAttorney;
use App\Actions\Orders\TransitionOrder;
use App\Actions\Orders\WaiveAgreement;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Orders\Payments;
use App\Models\CompanyBankAccount;
use App\Models\OnecOutboxEvent;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\OrderPayments;
use App\Services\Accounting\PaymentScheduleGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 invoices: AC-19..AC-21, BR-AC.3/4/12, invoice void, order close (AC-8).
 */
class InvoiceTest extends TestCase
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

    private function confirmedOrder(array $itemAttributes = []): Order
    {
        $offer = $this->draftOffer($this->rep);
        $this->addLine($offer, $this->pricedItem('1000.00', ['name' => 'Pump', ...$itemAttributes]), '10');
        app(SendOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email);
        app(AcceptOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email, 'ok');
        $order = Order::query()->where('offer_id', $offer->id)->firstOrFail();
        $order->forceFill(['prepayment_bp' => 5000])->save();
        // Spec 06 section 31: orders start on creation; terms set afterwards regenerate the schedule.
        app(PaymentScheduleGenerator::class)->generate($order->fresh(), $this->rep);
        app(OrderPayments::class)->recompute($order->fresh());

        return $order->fresh();
    }

    /** AC-19 */
    public function test_proforma_requests_the_prepayment_and_prints_in_every_language(): void
    {
        $order = $this->confirmedOrder(['accountant_code' => 'A-1', 'class_code' => '12345678901234567', 'package_code' => '1500001']);
        $proforma = app(IssueInvoice::class)->proforma($order, $this->accountant);

        $this->assertMatchesRegularExpression('/^PF-2026-\d{5}$/', $proforma->number);
        $this->assertSame([560_000, '2026-10-23'], [$proforma->requested_amount_minor, $proforma->due_on->toDateString()]);
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'invoice.issued')->where('aggregate_id', $proforma->id)->exists());

        foreach (['uz' => 'Hisob-faktura hisoblanmaydi.', 'ru' => 'Не является счётом-фактурой.', 'en' => 'This is not a VAT invoice.'] as $lang => $text) {
            $this->actingAs($this->accountant)->get(route('invoices.print', ['invoice' => $proforma, 'lang' => $lang]))
                ->assertOk()
                ->assertSee($text)
                ->assertSee('20208000900123456001')
                ->assertSee('12345678901234567')
                ->assertDontSee('A-1');
        }

        $this->actingAs($this->rep)->get(route('invoices.show', $proforma))->assertOk();
        $this->actingAs(User::factory()->sales()->create())->get(route('invoices.show', $proforma))->assertForbidden();
    }

    /** AC-20 */
    public function test_missing_codes_warn_or_block_per_setting(): void
    {
        $order = $this->confirmedOrder();

        try {
            app(IssueInvoice::class)->proforma($order, $this->accountant);
            $this->fail('Missing codes need an acknowledgement.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('codes_missing', $exception->key);
        }

        $proforma = app(IssueInvoice::class)->proforma($order, $this->accountant, [], true);
        $this->assertSame(['accountant_code', 'class_code', 'package_code'], array_column($proforma->code_warnings, 'code'));

        Setting::putMany(['accounting.invoice_code_validation' => json_encode(['accountant_code' => 'block', 'class_code' => 'block', 'package_code' => 'off'])]);

        try {
            app(IssueInvoice::class)->proforma($order, $this->accountant, [], true);
            $this->fail('Block mode must refuse.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('codes_blocking', $exception->key);
            $this->assertStringContainsString(__('accounting.codes.class_code'), $exception->getMessage());
            $this->assertStringNotContainsString(__('accounting.codes.accountant_code'), $exception->getMessage(), 'A missing accountant code never blocks (user decision).');
        }

        $service = $this->confirmedOrder(['type' => 'service', 'accountant_code' => 'S-1']);
        $this->assertSame(InvoiceStatus::Issued, app(IssueInvoice::class)->proforma($service, $this->accountant)->status, 'Services are outside the ESF goods scope.');
    }

    /** AC-21, AC-8 */
    public function test_tax_documents_follow_deliveries_and_complete_the_order(): void
    {
        $order = $this->confirmedOrder(['accountant_code' => 'A-1', 'class_code' => '12345678901234567', 'package_code' => '1500001']);
        app(WaiveAgreement::class)->handle($order, User::factory()->manager()->create(), 'paper');
        $line = $order->lines()->sole();

        // Spec 06 section 28: a legal-entity B2B order needs the power of attorney ticked first.
        $this->assertTrue($order->fresh()->poa_required);

        try {
            app(IssueInvoice::class)->registerTaxDocument($order, $this->accountant, ['type' => 'waybill', 'number' => 'W-1', 'issued_on' => '2026-10-18', 'lines' => [$line->id => '1']]);
            $this->fail('The power of attorney is missing.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('poa_missing', $exception->key);
        }

        app(RecordPowerOfAttorney::class)->tick($order->fresh(), $this->accountant, 'No. 12, A. Karimov');

        try {
            app(IssueInvoice::class)->registerTaxDocument($order, $this->accountant, ['type' => 'waybill', 'number' => 'W-1', 'issued_on' => '2026-10-18', 'lines' => [$line->id => '1']]);
            $this->fail('Nothing delivered yet.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('invoice_qty_exceeds', $exception->key);
        }

        // Deliveries arrive with job 5; simulate a posted delivery of everything.
        $line->forceFill(['delivered_qty' => '10'])->save();
        app(TransitionOrder::class)->handle($order->fresh(), OrderStatus::Packing, $this->rep);
        app(TransitionOrder::class)->handle($order->fresh(), OrderStatus::Packed, $this->rep);
        app(TransitionOrder::class)->handle($order->fresh(), OrderStatus::Delivered, null);

        $waybill = app(IssueInvoice::class)->registerTaxDocument($order, $this->accountant, ['type' => 'waybill', 'number' => 'W-1', 'issued_on' => '2026-10-18', 'esf_id' => 'ESF-77', 'lines' => [$line->id => '10']]);
        $this->assertSame([1_000_000, 120_000, 1_120_000], [$waybill->net_minor, $waybill->vat_minor, $waybill->gross_minor]);
        $this->assertSame('12345678901234567', $waybill->lines->first()->class_code);
        $this->assertSame(OrderStatus::Invoiced, $order->fresh()->status);
        $this->assertSame('10.000', (string) $line->fresh()->invoiced_qty);

        $this->actingAs($this->accountant)->get(route('invoices.print', $waybill))->assertOk()->assertSee('ESF-77')->assertDontSee('A-1');
        Setting::putMany(['accounting.invoice_print_columns' => json_encode(['accountant_code' => true, 'class_code' => false, 'package_code' => false])]);
        $this->actingAs($this->accountant)->get(route('invoices.print', $waybill))->assertSee('A-1')->assertDontSee('12345678901234567');

        try {
            app(CloseOrder::class)->handle($order->fresh(), $this->accountant);
            $this->fail('Not paid yet.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('close_not_paid', $exception->key);
        }

        $payment = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => 1_120_000, 'currency' => 'UZS', 'paid_on' => '2026-10-18', 'method' => 'cash', 'payer_name' => 'Buyer'], $this->accountant);
        app(AllocatePayment::class)->handle($payment, $order, 1_120_000, $this->accountant);
        $this->artisan('orders:auto-close')->assertSuccessful();

        $this->assertSame(OrderStatus::Closed, $order->fresh()->status);
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'order.closed')->exists());
    }

    public function test_invoice_void_needs_finance_and_gives_quantities_back(): void
    {
        $order = $this->confirmedOrder(['accountant_code' => 'A-1', 'class_code' => '12345678901234567', 'package_code' => '1500001']);
        $line = $order->lines()->sole();
        $line->forceFill(['delivered_qty' => '4'])->save();
        app(TransitionOrder::class)->handle($order->fresh(), OrderStatus::Packing, $this->rep);
        $act = app(IssueInvoice::class)->registerTaxDocument($order, $this->accountant, ['type' => 'act', 'number' => 'A-77', 'issued_on' => '2026-10-18', 'lines' => [$line->id => '4']]);

        app(VoidInvoice::class)->request($act, $this->accountant, 'Wrong quantity');
        $this->assertFalse($this->accountant->can('approveVoid', $act->fresh()));

        $finance = User::factory()->finance()->create();
        app(VoidInvoice::class)->approve($act, $finance);

        $this->assertSame(InvoiceStatus::Voided, $act->fresh()->status);
        $this->assertSame('0.000', (string) $line->fresh()->invoiced_qty);
        $this->assertTrue(OnecOutboxEvent::query()->where('event_type', 'invoice.voided')->exists());
    }

    public function test_order_payments_tab_issues_a_proforma(): void
    {
        $order = $this->confirmedOrder(['accountant_code' => 'A-1', 'class_code' => '12345678901234567', 'package_code' => '1500001']);

        Livewire::actingAs($this->accountant)->test(Payments::class, ['orderId' => $order->id])
            ->assertSee('data-schedule', false)
            ->call('openPanel', 'proforma')
            ->call('issueProforma')
            ->assertHasNoErrors();

        $this->assertSame(1, $order->invoices()->count());
        Livewire::actingAs($this->rep)->test(Payments::class, ['orderId' => $order->id])->assertDontSee('data-action="proforma"', false);
    }
}
