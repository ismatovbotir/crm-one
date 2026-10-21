<?php

namespace Tests\Feature\Orders;

use App\Actions\Orders\CreateManualOrder;
use App\Actions\Stock\PostDeliveryNote;
use App\Enums\AgreementStatus;
use App\Enums\DeliveryMethod;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Livewire\Orders\Create;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OneC\Outbox\OutboxPayloads;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderFlow;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 section 31: manual orders without an offer, priced like offers, delivery or self pick-up,
 * framework agreement auto-link, and the one-off start of existing new orders.
 */
class ManualOrderTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $rep;

    private Warehouse $warehouse;

    private Customer $customer;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
        $this->warehouse = Warehouse::factory()->create(['is_active' => true]);
        $this->customer = Customer::factory()->create(['owner_id' => $this->rep->id]);
        $this->item = $this->pricedItem('1000.00', ['name' => 'Pump']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function create(array $overrides = [], ?User $user = null): Order
    {
        return app(CreateManualOrder::class)->handle([
            'customer_id' => $this->customer->id, 'delivery_method' => 'delivery', 'delivery_address' => 'Tashkent, Amir Temur 1',
            'warehouse_id' => $this->warehouse->id, 'lines' => [['item_id' => $this->item->id, 'quantity' => '10']],
            ...$overrides,
        ], $user ?? $this->rep);
    }

    /** Standard document tabs (2026-10-20): Header | Items in one form; errors open their tab. */
    public function test_create_form_tabs_keep_input_and_open_the_tab_with_errors(): void
    {
        $component = Livewire::actingAs($this->rep)->test(Create::class)
            ->assertSet('tab', 'header')
            ->assertSeeHtml('data-document-summary')
            ->assertSeeHtml('id="doc-items"')
            ->call('selectCustomer', $this->customer->id)
            ->set('saleType', 'b2b')
            ->set('deliveryAddress', 'Tashkent, Amir Temur 1')
            ->set('tab', 'items')
            ->assertSeeHtml('data-manual-order-lines')
            ->call('save')
            ->assertHasErrors('lines')
            ->assertSet('tab', 'items')
            ->assertSeeHtml('data-tab-marker')
            ->set('deliveryAddress', '')
            ->call('addItem', $this->item->id)
            ->call('save')
            ->assertHasErrors('deliveryAddress')
            ->assertSet('tab', 'header')
            ->assertSet('saleType', 'b2b')
            ->assertCount('lines', 1);

        $this->assertSame(0, Order::query()->count());

        $component->set('deliveryAddress', 'Tashkent, Amir Temur 1')->call('save')->assertHasNoErrors();
        $this->assertSame(1, Order::query()->count());
    }

    public function test_manual_order_is_priced_like_an_offer_and_starts_at_once(): void
    {
        $order = $this->create(['note' => 'Urgent']);

        $this->assertSame(OrderSource::Manual, $order->source);
        $this->assertNull($order->offer_id);
        $this->assertSame(OrderStatus::Confirmed, $order->status, 'No confirmation step.');
        $this->assertSame([1_000_000, 120_000, 1_120_000], [$order->net_minor, $order->vat_minor, $order->gross_minor]);
        $this->assertSame($this->warehouse->id, $order->lines()->sole()->warehouse_id);
        $this->assertSame(SaleType::B2b, $order->sale_type);
        $this->assertTrue($order->poa_required);
        $this->assertSame(['manual', 'delivery'], [app(OutboxPayloads::class)->order($order)['source'], app(OutboxPayloads::class)->order($order)['delivery_method']]);
        $this->actingAs($this->rep)->get(route('orders.print', $order))->assertOk()->assertDontSee(__('documents.order.based_on_offer', ['number' => '']));
    }

    public function test_discount_limit_below_cost_and_ownership_cannot_be_bypassed(): void
    {
        $refused = function (array $overrides, string $key, ?User $user = null): void {
            try {
                $this->create($overrides, $user);
                $this->fail('Expected '.$key);
            } catch (OrderActionException $exception) {
                $this->assertSame($key, $exception->key);
            }
        };

        $refused(['lines' => [['item_id' => $this->item->id, 'quantity' => '1', 'discount_bp' => 600]]], 'discount_over_limit');
        $this->assertSame(OrderSource::Manual, $this->create(['lines' => [['item_id' => $this->item->id, 'quantity' => '1', 'discount_bp' => 500]]])->source, 'Within the 5 % limit.');

        $cheap = $this->pricedItem('100.00', ['name' => 'Cable', 'purchase_price_minor' => 50_000, 'purchase_currency' => 'UZS']);
        $refused(['lines' => [['item_id' => $cheap->id, 'quantity' => '1']]], 'below_cost');
        $refused(['delivery_address' => ''], 'address_required');
        $refused([], 'create_forbidden', User::factory()->sales()->create());

        $this->assertFalse(User::factory()->admin()->create()->can('create', Order::class));
        $this->actingAs(User::factory()->admin()->create())->get(route('orders.create'))->assertForbidden();
    }

    public function test_framework_agreement_is_linked_unless_a_new_agreement_is_chosen(): void
    {
        $framework = Agreement::factory()->status(AgreementStatus::Active)->create(['customer_id' => $this->customer->id]);

        $this->assertSame($framework->id, $this->create()->framework_agreement_id);
        $this->assertNull($this->create(['own_agreement' => true])->framework_agreement_id);
    }

    public function test_retail_self_pickup_needs_who_collected_before_posting(): void
    {
        $person = Customer::factory()->create(['owner_id' => $this->rep->id, 'legal_type' => 'individual', 'tax_id' => null]);
        $order = $this->create(['customer_id' => $person->id, 'delivery_method' => 'pickup', 'delivery_address' => null]);

        $this->assertSame(SaleType::Retail, $order->sale_type, 'Private persons default to retail.');
        $this->assertSame(DeliveryMethod::Pickup, $order->delivery_method);
        $this->assertFalse($order->poa_required);
        $this->assertContains('pickup', array_column(app(OrderFlow::class)->steps($order), 'key'));

        $order->forceFill(['status' => OrderStatus::Packed])->save();
        $this->assertSame('hand_over', app(OrderFlow::class)->next($order->fresh()));
        $delivery = Delivery::query()->create(['number' => 'DN-T-1', 'order_id' => $order->id, 'status' => 'draft', 'warehouse_id' => $this->warehouse->id]);
        $delivery->forceFill(['packed_at' => now()])->save();

        try {
            app(PostDeliveryNote::class)->handle($delivery, User::factory()->stockManager()->create());
            $this->fail('Pick-up details are required.');
        } catch (StockActionException $exception) {
            $this->assertSame('pickup_details_required', $exception->key);
        }

        $this->assertNull(app(OutboxPayloads::class)->delivery($delivery->fresh())['pickup']['person'] ?? null);
    }

    public function test_screen_creates_the_order(): void
    {
        Livewire::actingAs($this->rep)->test(Create::class)
            ->call('selectCustomer', $this->customer->id)
            ->assertSet('saleType', 'b2b')
            ->set('itemSearch', 'Pump')
            ->call('addItem', $this->item->id)
            ->set('lines.0.quantity', '2')
            ->set('deliveryAddress', 'Chilonzor 5')
            ->call('save')
            ->assertHasNoErrors();

        $order = Order::query()->where('source', 'manual')->sole();
        $this->assertSame('2.000', (string) $order->lines()->sole()->quantity);
        $this->actingAs($this->rep)->get(route('orders.index'))->assertSee(route('orders.create'), false);
    }

    public function test_start_new_command_dry_run_then_apply(): void
    {
        $order = $this->create();
        $order->forceFill(['status' => OrderStatus::New])->save();

        $this->artisan('orders:start-new')->expectsOutputToContain($order->number)->assertSuccessful();
        $this->assertSame(OrderStatus::New, $order->fresh()->status, 'Dry run changes nothing.');

        $this->artisan('orders:start-new', ['--apply' => true])->assertSuccessful();
        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }
}
