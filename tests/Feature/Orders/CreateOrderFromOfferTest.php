<?php

namespace Tests\Feature\Orders;

use App\Actions\Offers\AcceptOffer;
use App\Actions\Offers\SendOffer;
use App\Actions\Orders\CreateOrderFromOffer;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\SelectionChannel;
use App\Livewire\Offers\Show as OfferShow;
use App\Models\Order;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 DEC-1, BR-OR.1/2/4, AC-1: one order per accepted offer, fixed + chosen lines only,
 * amounts and codes copied verbatim, created automatically after acceptance.
 */
class CreateOrderFromOfferTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_accepting_an_offer_creates_exactly_one_order_with_the_chosen_lines_and_totals(): void
    {
        $rep = User::factory()->sales()->create();
        ['order' => $order, 'offer' => $offer, 'lines' => $lines] = $this->acceptedFixtureOrder($rep);

        $again = app(CreateOrderFromOffer::class)->handle($offer, $rep);

        $this->assertSame($order->id, $again->id);
        $this->assertSame(1, Order::query()->where('offer_id', $offer->id)->count());
        // Spec 06 section 31: no separate confirmation, the order starts on creation.
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->payment_status);
        $this->assertMatchesRegularExpression('/^OR-\d{4}-\d{5}$/', $order->number);
        $this->assertSame([$offer->selected_net_minor, $offer->selected_vat_minor, $offer->selected_gross_minor], [$order->net_minor, $order->vat_minor, $order->gross_minor]);
        $this->assertSame([$lines['fixed']->id, $lines['C']->id, $lines['Y']->id], $order->lines->pluck('offer_line_id')->all());
        $this->assertSame(['1', '2C', '3B'], $order->lines->pluck('option_label')->all());
        $this->assertSame($order->gross_minor, (int) $order->lines->sum('line_gross_minor'));
        $this->assertSame($rep->id, $order->owner_id);

        $transition = StatusTransition::query()->where('subject_type', 'order')->where('subject_id', $order->id)->firstOrFail();
        $this->assertNull($transition->from_status);
        $this->assertSame('new', $transition->to_status);
        $this->assertStringContainsString($offer->displayNumber(), (string) $transition->reasonLabel());
    }

    public function test_codes_are_snapshots_and_survive_later_item_edits(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $item = $this->pricedItem('1000.00', ['accountant_code' => 'ACC-1', 'class_code' => '12345678901234567', 'package_code' => '1500001']);
        $this->addLine($offer, $item, '2');
        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);
        app(AcceptOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email, 'ok');

        $item->forceFill(['accountant_code' => 'CHANGED', 'class_code' => '99999999999999999', 'package_code' => '1'])->save();
        $line = Order::query()->where('offer_id', $offer->id)->firstOrFail()->lines()->firstOrFail();

        $this->assertSame(['ACC-1', '12345678901234567', '1500001'], [$line->accountant_code, $line->class_code, $line->package_code]);
        $this->assertSame('2.000', (string) $line->quantity);
    }

    public function test_an_offer_that_is_not_accepted_cannot_become_an_order(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);

        $this->expectException(OrderActionException::class);
        app(CreateOrderFromOffer::class)->handle($offer, $rep);
    }

    public function test_the_offer_page_links_to_the_order_and_offers_a_fallback_button(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep);
        $offer = $order->offer;

        Livewire::actingAs($rep)->test(OfferShow::class, ['offer' => $offer])
            ->assertSee('data-order-link', false)
            ->assertSee($order->number)
            ->assertDontSee('data-action="create-order"', false);
    }
}
