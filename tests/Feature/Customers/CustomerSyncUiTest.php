<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\Form;
use App\Livewire\Customers\Show;
use App\Models\Customer;
use App\Models\IntegrationFeed;
use App\Models\Setting;
use App\Models\User;
use App\Services\OneC\OneCSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 02 rev. 3 section 12 / AC-I.47a: sync badge with "pending since", stale poll warning,
 * Resync permission.
 */
class CustomerSyncUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1', OneCSettings::CUSTOMERS_POLL_STALE_KEY => '30']);
    }

    public function test_pending_customer_shows_since_when_and_a_stale_warning_until_1c_polls(): void
    {
        $owner = User::factory()->sales()->create();
        $customer = Customer::factory()->syncing()->ownedBy($owner)->create();

        $this->actingAs($owner)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('data-pending-since', false)
            ->assertSee('data-poll-stale', false)
            ->assertSee(__('integration.customer_sync.poll_never'));

        IntegrationFeed::touchContact(IntegrationFeed::CUSTOMERS_POLL);

        $this->get(route('customers.show', $customer))->assertDontSee('data-poll-stale', false);

        $this->travel(31)->minutes();
        $this->get(route('customers.show', $customer))->assertSee('data-poll-stale', false);
    }

    public function test_resync_is_limited_to_own_customers_for_sales_and_needs_the_exchange_on(): void
    {
        $owner = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        $customer = Customer::factory()->syncing()->ownedBy($owner)->create();

        $this->assertTrue($owner->can('resync', $customer));
        $this->assertFalse($other->can('resync', $customer));
        $this->assertTrue(User::factory()->manager()->create()->can('resync', $customer));
        $this->assertFalse(User::factory()->finance()->create()->can('resync', $customer));

        Livewire::actingAs($other)->test(Show::class, ['customer' => $customer])->call('resync')->assertForbidden();

        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '0']);
        $this->assertFalse($owner->fresh()->can('resync', $customer));
    }

    public function test_resync_of_a_customer_without_tax_id_explains_why(): void
    {
        $owner = User::factory()->sales()->create();
        $customer = Customer::factory()->syncing()->ownedBy($owner)->create(['tax_id' => null]);

        // Spec 02 rev. 4: no tax ID, no sync: Resync is not offered and the form refuses the switch.
        Livewire::actingAs($owner)->test(Show::class, ['customer' => $customer])->call('resync')->assertForbidden();
        Livewire::actingAs($owner)->test(Form::class, ['customer' => $customer])
            ->set('sync_with_1c', true)->call('save')->assertHasErrors('sync_with_1c')
            ->assertSee(__('customers.errors.sync_needs_tax_id'));

        $this->assertSame(0, $customer->outboxEntries()->count());
    }

    public function test_settings_page_shows_last_contact_and_no_1c_connection_settings(): void
    {
        IntegrationFeed::touchContact(IntegrationFeed::CUSTOMERS_PUSH);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('settings.integrations'))
            ->assertOk()
            ->assertSee('data-customer-exchange', false)
            ->assertSee('data-last-contact="poll"', false)
            ->assertSee('data-last-contact="push"', false)
            ->assertSee(__('integration.customer_exchange.poll_stale_badge'))
            ->assertDontSee('Test connection');
    }
}
