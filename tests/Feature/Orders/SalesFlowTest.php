<?php

namespace Tests\Feature\Orders;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\CloseOrder;
use App\Actions\Accounting\IssueInvoice;
use App\Actions\Accounting\RecordPayment;
use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Orders\ChangeSaleType;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\StartPackaging;
use App\Actions\Stock\ClaimSerials;
use App\Actions\Stock\ManageDeliveryNote;
use App\Actions\Stock\Packaging;
use App\Actions\Stock\PostDeliveryNote;
use App\Enums\AgreementKind;
use App\Enums\InvoiceType;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Enums\SelectionChannel;
use App\Livewire\Orders\Payments;
use App\Livewire\Orders\Show;
use App\Livewire\Stock\DeliveryShow;
use App\Livewire\Stock\PackagingQueue;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 06 section 25 (target flow lead → customer → offer → order and agreement → payment →
 * packaging with serial numbers → delivery): sale type, payment gate, packaging gate, delivery
 * gate, sales receipt, retail closing, permissions and the stepper.
 */
class SalesFlowTest extends TestCase
{
    use BuildsOrders, BuildsStock {
        BuildsOrders::pricedItem insteadof BuildsStock;
        BuildsOrders::draftOffer insteadof BuildsStock;
        BuildsOrders::addLine insteadof BuildsStock;
        BuildsOrders::addGroup insteadof BuildsStock;
        BuildsOrders::fixtureOffer insteadof BuildsStock;
    }
    use RefreshDatabase;

    private User $rep;

    private User $stock;

    private User $accountant;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpCompany();
        $this->rep = User::factory()->sales()->create();
        $this->stock = User::factory()->stockManager()->create();
        $this->accountant = User::factory()->accountant()->create();
        $this->main = Warehouse::factory()->create(['name' => 'Main']);
    }

    private function serializedOrder(SaleType $saleType, string $quantity = '2'): Order
    {
        $customer = Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS', 'sale_type' => $saleType]);
        $item = $this->serializedItem($this->main, '10');
        $this->receiveSerials($this->stock, $this->main, $item, ['SF-1', 'SF-2', 'SF-3']);
        $offer = $this->draftOffer($this->rep, $customer);
        $this->addLine($offer, $item, $quantity);
        app(SendOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email);
        app(AcceptOffer::class)->handle($offer->fresh(), $this->rep, SelectionChannel::Email, 'ok');

        return Order::query()->where('offer_id', $offer->id)->firstOrFail();
    }

    private function payInFull(Order $order): void
    {
        $order->refresh();
        $payment = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => $order->gross_minor, 'currency' => 'UZS', 'paid_on' => now()->toDateString(), 'method' => 'cash', 'payer_name' => 'Buyer'], $this->accountant);
        app(AllocatePayment::class)->handle($payment, $order, $order->gross_minor, $this->accountant);
    }

    public function test_sale_type_comes_from_the_customer_and_retail_needs_full_payment_before_packaging(): void
    {
        $order = $this->serializedOrder(SaleType::Retail);
        $this->assertSame(SaleType::Retail, $order->sale_type);
        $this->assertFalse($order->agreement_required, 'Retail needs no agreement unless one is added.');
        $this->assertSame(10000, $order->prepayment_bp);
        app(ConfirmOrder::class)->handle($order, $this->rep);

        try {
            app(StartPackaging::class)->handle($order->fresh(), $this->stock);
            $this->fail('Retail packaging waits for the full payment.');
        } catch (OrderActionException $exception) {
            $this->assertSame('on_hold', $exception->key);
        }

        $this->actingAs($this->rep)->get(route('orders.show', $order))->assertOk()
            ->assertSee('data-order-flow', false)->assertSee('data-next-action="pay_full"', false);

        $this->payInFull($order);
        app(StartPackaging::class)->handle($order->fresh(), $this->stock);
        $this->assertSame(OrderStatus::Packing, $order->fresh()->status);
    }

    public function test_b2b_with_credit_terms_starts_packaging_without_payment(): void
    {
        config(['orders.block_fulfilment_without_agreement' => false]);
        $order = $this->serializedOrder(SaleType::B2b);
        $this->assertSame(SaleType::B2b, $order->sale_type);
        $this->assertNull($order->prepayment_bp);
        app(ConfirmOrder::class)->handle($order, $this->rep);

        $this->assertFalse($this->rep->can('startPackaging', $order->fresh()), 'Packaging is warehouse work.');
        $this->assertTrue($this->stock->can('startPackaging', $order->fresh()));
        app(StartPackaging::class)->handle($order->fresh(), $this->stock);
        $this->assertSame(OrderStatus::Packing, $order->fresh()->status);
    }

    public function test_packaging_needs_all_serials_and_delivery_needs_completed_packaging(): void
    {
        $order = $this->serializedOrder(SaleType::Retail);
        app(ConfirmOrder::class)->handle($order, $this->rep);
        $this->payInFull($order);

        $this->expectPackingRefused(fn () => app(ManageDeliveryNote::class)->create($order->fresh(), $this->stock, $this->main->id), 'order_not_packing');
        app(StartPackaging::class)->handle($order->fresh(), $this->stock);

        $this->assertFalse($this->rep->can('create', [Delivery::class, $order->fresh()]), 'Sales do not draft packing lists any more.');
        $list = app(ManageDeliveryNote::class)->create($order->fresh(), $this->stock, $this->main->id);
        $this->expectPackingRefused(fn () => app(ManageDeliveryNote::class)->create($order->fresh(), $this->stock, $this->main->id), 'packing_list_open');

        app(ClaimSerials::class)->handle($list->lines()->sole(), ['SF-1'], $this->stock);
        $this->expectPackingRefused(fn () => app(Packaging::class)->complete($list, $this->stock), 'serials_incomplete');
        $this->expectPackingRefused(fn () => app(PostDeliveryNote::class)->handle($list, $this->stock), 'not_packed');

        app(ClaimSerials::class)->handle($list->lines()->sole()->fresh(), ['SF-2'], $this->stock);
        Livewire::actingAs($this->stock)->test(DeliveryShow::class, ['delivery' => $list])->call('completePackaging')->assertHasNoErrors();
        $this->assertSame(OrderStatus::Packed, $order->fresh()->status);
        $this->assertTrue($list->fresh()->isPacked());

        // A packed list is locked: no more serial changes until packaging is reopened.
        $this->expectPackingRefused(fn () => app(ClaimSerials::class)->handle($list->lines()->sole()->fresh(), ['SF-3'], $this->stock), 'packing_locked');
        Livewire::actingAs($this->stock)->test(DeliveryShow::class, ['delivery' => $list])->set('reopening', true)->set('reopenReason', 'Wrong box')->call('reopenPackaging')->assertHasNoErrors();
        $this->assertSame(OrderStatus::Packing, $order->fresh()->status);
        $this->assertFalse($list->fresh()->isPacked());

        app(Packaging::class)->complete($list->fresh(), $this->stock);
        $this->assertFalse($this->rep->can('post', $list->fresh()));
        app(PostDeliveryNote::class)->handle($list->fresh(), $this->stock);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_retail_sales_receipt_prints_in_every_language_and_closes_the_order(): void
    {
        $order = $this->serializedOrder(SaleType::Retail, '1');
        app(ConfirmOrder::class)->handle($order, $this->rep);

        $this->assertFalse($this->accountant->can('issueReceipt', $order->fresh()), 'Only once fully paid.');
        $this->assertFalse($this->accountant->can('issueProforma', $order->fresh()), 'Retail has no proforma.');
        $this->payInFull($order);
        $this->assertFalse($this->rep->can('issueReceipt', $order->fresh()), 'The accountant issues payment documents.');

        Livewire::actingAs($this->accountant)->test(Payments::class, ['orderId' => $order->id])->call('issueReceipt');
        $receipt = $order->invoices()->where('type', InvoiceType::SalesReceipt)->sole();
        $this->assertMatchesRegularExpression('/^SR-\d{4}-\d{5}$/', $receipt->number);
        $this->assertSame($order->fresh()->gross_minor, $receipt->gross_minor);
        $this->assertSame('0.000', (string) $order->lines()->sole()->invoiced_qty, 'A receipt is not a tax document.');

        foreach (['uz', 'ru', 'en'] as $lang) {
            $this->actingAs($this->accountant)->get(route('invoices.print', ['invoice' => $receipt, 'lang' => $lang]))->assertOk()
                ->assertSee(__('documents.sales_receipt.title', [], $lang))
                ->assertSee(__('documents.sales_receipt.received', [], $lang))
                ->assertSee('data-receipt-payments', false);
        }

        try {
            app(IssueInvoice::class)->salesReceipt($order->fresh(), $this->accountant);
            $this->fail('One receipt per order.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('receipt_exists', $exception->key);
        }

        app(StartPackaging::class)->handle($order->fresh(), $this->stock);
        $list = app(ManageDeliveryNote::class)->create($order->fresh(), $this->stock, $this->main->id);
        app(ClaimSerials::class)->handle($list->lines()->sole(), ['SF-1'], $this->stock);
        $this->packAndPost($list, $this->stock);

        $this->assertTrue($this->accountant->can('close', $order->fresh()));
        app(CloseOrder::class)->handle($order->fresh(), $this->accountant);
        $this->assertSame(OrderStatus::Closed, $order->fresh()->status);
    }

    public function test_b2b_orders_get_no_receipt_and_close_only_after_the_tax_invoice(): void
    {
        $order = $this->serializedOrder(SaleType::B2b, '1');
        app(ConfirmOrder::class)->handle($order, $this->rep);

        try {
            app(IssueInvoice::class)->salesReceipt($order->fresh(), $this->accountant);
            $this->fail('B2B sells against invoices.');
        } catch (AccountingActionException $exception) {
            $this->assertSame('receipt_retail_only', $exception->key);
        }

        $this->assertSame('close_not_invoiced', app(CloseOrder::class)->blocker($order->fresh()));
    }

    public function test_sale_type_can_be_changed_while_new_and_a_retail_agreement_becomes_required(): void
    {
        $this->seedAgreementTemplates();
        $customer = $this->agreementReadyCustomer($this->rep);
        $order = $this->simpleOrder($this->rep, '1', '10', $customer);

        Livewire::actingAs($this->rep)->test(Show::class, ['order' => $order])
            ->call('openPanel', 'sale_type')->set('saleType', 'retail')->call('changeSaleType')->assertHasNoErrors();
        $order->refresh();
        $this->assertSame(SaleType::Retail, $order->sale_type);
        $this->assertFalse($order->agreement_required);
        $this->assertSame(10000, $order->prepayment_bp);

        app(GenerateAgreement::class)->handle(AgreementKind::Order, $customer, $order, $this->accountant);
        $this->assertTrue($order->fresh()->agreement_required, 'Once an agreement is added to a retail order it is required.');

        // Spec 06 section 31: the sale type is locked once packaging has started.
        $order->forceFill(['started_at' => now()])->save();
        $this->expectException(OrderActionException::class);
        app(ChangeSaleType::class)->handle($order->fresh(), SaleType::B2b, $this->rep);
    }

    public function test_packaging_queue_is_for_the_warehouse(): void
    {
        $order = $this->serializedOrder(SaleType::Retail);
        app(ConfirmOrder::class)->handle($order, $this->rep);

        Livewire::actingAs($this->stock)->test(PackagingQueue::class)->assertSee($order->number)->assertSee(__('stock.packaging.waiting_payment'));
        $this->actingAs($this->rep)->get(route('packaging.index'))->assertForbidden();
        $this->actingAs($this->stock)->get(route('packaging.index'))->assertOk()
            ->assertSeeInOrder([route('packaging.index'), route('delivery-notes.index'), route('goods-receipts.index')], false);
    }

    private function expectPackingRefused(callable $action, string $key): void
    {
        try {
            $action();
            $this->fail('Expected refusal: '.$key);
        } catch (StockActionException $exception) {
            $this->assertSame($key, $exception->key);
        }
    }
}
