<?php

namespace Tests\Feature;

use App\Actions\Leads\LeadWorkflow;
use App\Actions\Offers\SendOffer;
use App\Enums\SelectionChannel;
use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Search\Index;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Lead;
use App\Models\User;
use App\Services\Search\GlobalSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Roadmap step 5: global search limited to what the user may see, and the customer 360 timeline.
 */
class SearchAndTimelineTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    public function test_search_finds_records_across_modules_within_visibility(): void
    {
        $rep = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        Lead::factory()->ownedBy($rep)->create(['company_name' => 'Zarafshon Mining One']);
        Lead::factory()->ownedBy($other)->create(['company_name' => 'Zarafshon Mining Two']);
        Customer::factory()->ownedBy($rep)->create(['name' => 'Zarafshon Gold']);
        Item::factory()->create(['name' => 'Zarafshon pump']);

        $groups = app(GlobalSearch::class)->search($rep, 'zarafshon');
        $this->assertSame(['leads', 'customers', 'items'], array_keys($groups));
        $this->assertCount(1, $groups['leads'], 'Another rep\'s lead is not found.');
        $this->assertStringContainsString('Mining One', $groups['leads'][0]['title']);

        $accountant = User::factory()->accountant()->create();
        $this->assertArrayNotHasKey('leads', app(GlobalSearch::class)->search($accountant, 'zarafshon'));
        $this->assertSame([], app(GlobalSearch::class)->search($rep, 'z'));

        Livewire::actingAs($rep)->withQueryParams(['q' => 'Zarafshon'])->test(Index::class)->assertSee('Zarafshon Gold')->assertSee(__('search.groups.items'));
        $this->actingAs($rep)->get(route('dashboard'))->assertSee('data-global-search', false);
    }

    public function test_like_wildcards_are_escaped(): void
    {
        $rep = User::factory()->sales()->create();
        Customer::factory()->ownedBy($rep)->create(['name' => 'Plain Name']);

        $this->assertSame([], app(GlobalSearch::class)->search($rep, '%%'));
    }

    public function test_customer_timeline_merges_related_history(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create(['currency' => 'UZS']);
        $lead = Lead::factory()->ownedBy($rep)->create(['customer_id' => $customer->id]);
        app(LeadWorkflow::class)->markContacted($lead, $rep);
        $offer = $this->draftOffer($rep, $customer);
        $this->addLine($offer, $this->pricedItem('100.00'));
        app(SendOffer::class)->handle($offer->fresh(), $rep, SelectionChannel::Email);

        Livewire::actingAs($rep)->withQueryParams(['tab' => 'timeline'])->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee('data-customer-timeline', false)
            ->assertSee(__('timeline.types.lead').' '.$lead->number)
            ->assertSee(__('timeline.types.offer').' '.$offer->fresh()->displayNumber())
            ->assertSee(route('offers.show', $offer), false);

        // A colleague who cannot see the offer or lead does not see their history.
        $colleague = User::factory()->sales()->create();
        Livewire::actingAs($colleague)->withQueryParams(['tab' => 'timeline'])->test(CustomerShow::class, ['customer' => $customer])
            ->assertDontSee($lead->number);
    }
}
