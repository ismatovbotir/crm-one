<?php

namespace Tests\Feature\Offers;

use App\Enums\OfferStatus;
use App\Livewire\Offers\Index;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 10.1: list filters (status, owner, customer, date) and search; navigation entry.
 */
class OfferIndexTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    public function test_filters_and_search(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->sales()->create();
        $alpha = Customer::factory()->ownedBy($rep)->create(['name' => 'Alpha Trade']);
        $beta = Customer::factory()->ownedBy($rep)->create(['name' => 'Beta Foods']);
        $draft = Offer::factory()->ownedBy($rep)->forCustomer($alpha)->create(['number' => 'OF-2026-10001']);
        $sent = Offer::factory()->ownedBy($manager)->forCustomer($beta)->status(OfferStatus::Sent)->create(['number' => 'OF-2026-10002']);
        $old = Offer::factory()->ownedBy($manager)->forCustomer($beta)->create(['number' => 'OF-2026-10003']);
        $old->forceFill(['created_at' => now()->subMonths(2)])->save();

        Livewire::actingAs($manager)->test(Index::class)
            ->assertSee(['OF-2026-10001', 'OF-2026-10002', 'OF-2026-10003'])
            ->set('search', 'Alpha')->assertSee('OF-2026-10001')->assertDontSee('OF-2026-10002')
            ->set('search', '10002')->assertSee('OF-2026-10002')->assertDontSee('OF-2026-10001')
            ->call('resetFilters')
            ->set('status', 'sent')->assertSee('OF-2026-10002')->assertDontSee('OF-2026-10001')
            ->call('resetFilters')
            ->set('owner', 'mine')->assertSee('OF-2026-10002')->assertDontSee('OF-2026-10001')
            ->call('resetFilters')
            ->set('customer', (string) $alpha->id)->assertSee('OF-2026-10001')->assertDontSee('OF-2026-10003')
            ->call('resetFilters')
            ->set('from', now()->subWeek()->toDateString())->assertSee('OF-2026-10001')->assertDontSee('OF-2026-10003');
    }

    public function test_navigation_shows_offers_to_sales_but_not_to_staff_without_rights(): void
    {
        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertSee(route('offers.index'), false);

        $this->actingAs(User::factory()->staff()->inDepartment('logistics')->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('offers.index'), false);
    }
}
