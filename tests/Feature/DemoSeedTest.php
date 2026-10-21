<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Item;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dev seeder (stand-in for the 1C feed) produces a usable demo and every screen renders with it.
 */
class DemoSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_fills_the_catalog_and_customers_and_screens_render(): void
    {
        $this->seed();
        $this->seed(); // idempotent

        $this->assertSame(17, Item::query()->count());
        $this->assertSame(0, Item::query()->whereNull('sale_price_minor')->count(), 'Every demo item has its single sale price.');
        $this->assertNotNull(Item::query()->where('sku', '00-00001001')->firstOrFail()->stockCostPrice());
        $this->assertSame(1, Customer::onlyTrashed()->count());
        $this->assertSame(1, User::query()->where('email', 'logistics@crm.test')->firstOrFail()->activeDepartments()->count());
        $this->assertSame(5, Offer::query()->count());
        $this->assertEqualsCanonicalizing(
            ['partially_selected', 'draft', 'pending_approval', 'accepted', 'cancelled'],
            Offer::query()->pluck('status')->map(fn ($status) => $status->value)->all(),
        );
        $this->assertTrue(Offer::query()->whereHas('groups', fn ($q) => $q->where('kind', 'alternative'))->exists());
        $this->assertTrue(Offer::query()->whereHas('groups', fn ($q) => $q->where('kind', 'optional'))->exists());
        $this->assertSame(4, Item::query()->where('is_serialized', true)->count());

        $admin = User::query()->where('email', 'admin@crm.test')->firstOrFail();
        $item = Item::query()->where('sku', '00-00001001')->firstOrFail();
        $customer = Customer::query()->where('tax_id', '301234567')->firstOrFail();

        $this->actingAs($admin);

        foreach ([
            route('customers.index'), route('customers.show', $customer), route('customers.edit', $customer), route('customers.create'),
            route('items.index'), route('items.show', $item), route('categories.index'),
            route('departments.index'), route('users.index'),
            route('offers.index'),
            ...Offer::query()->get()->flatMap(fn (Offer $offer) => [route('offers.show', $offer), route('offers.print', $offer)])->all(),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        // Leads demo (Spec 04): five leads in different statuses, one stale.
        $this->assertSame(5, Lead::query()->count());
        $this->assertEqualsCanonicalizing(['new', 'contacted', 'qualified', 'lost', 'new'], Lead::query()->pluck('status')->map(fn ($status) => $status->value)->all());
        $this->assertSame(1, Lead::query()->stale()->count());
        $this->actingAs(User::query()->where('email', 'sales@crm.test')->firstOrFail());

        foreach ([route('leads.index'), route('leads.create'), ...Lead::query()->where('owner_id', auth()->id())->get()->flatMap(fn (Lead $lead) => [route('leads.show', $lead), route('leads.edit', $lead)])->all()] as $url) {
            $this->get($url)->assertOk();
        }

        // Admin is read-only on offers; the manager creates and edits.
        $this->actingAs(User::query()->where('email', 'manager@crm.test')->firstOrFail());
        $this->get(route('offers.create'))->assertOk();
        $this->get(route('offers.edit', Offer::query()->where('status', 'draft')->firstOrFail()))->assertOk();

        // Procurement demo (Spec 03): counterparties and a transport tender draft.
        $this->actingAs(User::query()->where('email', 'logistics@crm.test')->firstOrFail());
        $tender = Tender::query()->sole();
        $this->assertSame(3, $tender->invitations()->count());

        foreach ([route('tenders.transport.index'), route('tenders.show', $tender), route('tenders.notice', $tender), route('counterparties.index'), route('approvals.index')] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
