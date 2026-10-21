<?php

namespace Tests\Feature\Api\OneC;

use App\Enums\IntegrationAbility;
use App\Enums\SyncStatus;
use App\Livewire\Customers\Show;
use App\Livewire\Partners\Form;
use App\Livewire\Settings\Integrations;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\IntegrationFeed;
use App\Models\Setting;
use App\Models\User;
use App\Services\OneC\OneCSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 rev. 3 AC-I.32..I.40, I.47a/b: CRM customers wait in an outbox that 1C polls and
 * acknowledges; the CRM never calls 1C.
 */
class CustomerOutboxTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1']);
        $this->issueToken([IntegrationAbility::CustomersRead->value, IntegrationAbility::CustomersWrite->value]);
    }

    /** AC-I.32 */
    public function test_customer_created_in_the_crm_waits_in_the_outbox_without_any_http_call(): void
    {
        Http::fake();
        $rep = User::factory()->sales()->create();

        Livewire::actingAs($rep)->withQueryParams(['role' => 'customer'])->test(Form::class)
            ->set('form.name', 'Alpha Trade')
            ->set('form.legal_name', 'Alpha Trade MChJ')
            ->set('form.tax_id', '301234567')
            ->set('syncWith1c', true)
            ->set('form.contact_first_name', 'Aziz')
            ->set('form.contact_email', 'aziz@alpha.uz')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('tax_id', '301234567')->firstOrFail();
        $this->assertSame(SyncStatus::Pending, $customer->sync_status);
        $this->assertNotNull($customer->sync_queued_at);
        $this->assertSame(1, $customer->outboxEntries()->count(), 'The contact reuses the undelivered entry.');
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $customer->id, 'event' => 'sync_queued']);
        Http::assertNothingSent();
    }

    /** AC-I.39 */
    public function test_customer_without_valid_tax_id_is_not_synced_until_fixed(): void
    {
        $customer = Customer::factory()->syncing()->create(['tax_id' => null]);

        $this->assertSame(SyncStatus::NotSynced, $customer->fresh()->sync_status);
        $this->assertSame('tax_id_missing', $customer->fresh()->sync_error);
        $this->assertSame(0, $customer->outboxEntries()->count());

        $customer->update(['tax_id' => '12345']);
        $this->assertSame('tax_id_invalid', $customer->fresh()->sync_error);

        $customer->update(['tax_id' => '301234567']);
        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);
        $this->assertSame(1, $customer->outboxEntries()->open()->count());
    }

    /** AC-I.33 */
    public function test_1c_polls_pending_customers_with_contacts(): void
    {
        $customer = Customer::factory()->syncing()->create(['name' => 'Beta Foods', 'tax_id' => '302765432']);
        Contact::factory()->create(['partner_id' => $customer->id, 'first_name' => 'Bekzod', 'email' => 'b@beta.uz']);

        $response = $this->onec('GET', '/customers/pending')->assertOk()
            ->assertJsonPath('data.0.crm_id', $customer->uuid)
            ->assertJsonPath('data.0.name', 'Beta Foods')
            ->assertJsonPath('data.0.tax_id', '302765432')
            ->assertJsonPath('data.0.external_id', null)
            ->assertJsonPath('data.0.reason', 'created')
            ->assertJsonPath('data.0.delivery_count', 1)
            ->assertJsonPath('data.0.contacts.0.first_name', 'Bekzod')
            ->assertJsonPath('has_more', false);

        $entry = $customer->outboxEntries()->firstOrFail();
        $this->assertSame($entry->id, $response->json('data.0.outbox_id'));
        $this->assertSame((string) $entry->id, $response->json('next_cursor'));
        $this->assertNotNull($entry->first_delivered_at);
        $this->assertNotNull(IntegrationFeed::lastContact(IntegrationFeed::CUSTOMERS_POLL));
    }

    public function test_polling_needs_the_customers_read_ability_and_the_exchange_switched_on(): void
    {
        $this->issueToken([IntegrationAbility::CustomersWrite->value]);
        $this->onec('GET', '/customers/pending')->assertForbidden()->assertJsonPath('error.code', 'forbidden_ability');

        $this->issueToken([IntegrationAbility::ItemsWrite->value]);
        $this->onec('POST', '/customers/ack', ['crm_id' => (string) Str::uuid(), 'status' => 'failed'])->assertForbidden();

        $this->issueToken([IntegrationAbility::CustomersRead->value]);
        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '0']);
        $this->onec('GET', '/customers/pending')->assertStatus(503)->assertJsonPath('error.code', 'integration_disabled');
    }

    public function test_poll_pages_with_a_cursor_and_validates_parameters(): void
    {
        $customers = Customer::factory()->syncing()->count(3)->create();

        $first = $this->onec('GET', '/customers/pending?limit=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('has_more', true);
        $this->onec('GET', '/customers/pending?limit=2&cursor='.$first->json('next_cursor'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.crm_id', $customers[2]->uuid)
            ->assertJsonPath('has_more', false);

        // A new run starts without a cursor: unacknowledged entries come back.
        $this->onec('GET', '/customers/pending')->assertJsonCount(3, 'data')->assertJsonPath('data.0.delivery_count', 2);

        $this->onec('GET', '/customers/pending?limit=0')->assertStatus(422);
        $this->onec('GET', '/customers/pending?cursor=abc')->assertStatus(422);
    }

    /** AC-I.34 */
    public function test_synced_ack_links_the_customer_and_is_idempotent(): void
    {
        $customer = Customer::factory()->syncing()->create();
        $contact = Contact::factory()->create(['partner_id' => $customer->id]);
        $outboxId = $this->onec('GET', '/customers/pending')->json('data.0.outbox_id');
        $guid = $this->guid('cp-1');
        $ack = [
            'crm_id' => $customer->uuid, 'outbox_id' => $outboxId, 'status' => 'synced', 'external_id' => $guid,
            'external_code' => '000012345', 'external_version' => 'AAAAAQ==',
            'contacts' => [['crm_id' => $contact->uuid, 'external_id' => $this->guid('contact-1')]],
        ];

        $this->onec('POST', '/customers/ack', ['acks' => [$ack]])->assertOk()->assertJsonPath('results.0.result', 'applied');

        $customer->refresh();
        $this->assertSame(SyncStatus::Synced, $customer->sync_status);
        $this->assertSame($guid, $customer->external_id);
        $this->assertSame('000012345', $customer->external_code);
        $this->assertNull($customer->sync_queued_at);
        $this->assertSame($this->guid('contact-1'), $contact->fresh()->external_id);
        $this->assertSame(0, $customer->outboxEntries()->open()->count());

        // Same ack again (single-object form): nothing changes.
        $this->onec('POST', '/customers/ack', $ack)->assertOk()->assertJsonPath('results.0.result', 'already_applied');
        $this->assertSame(1, $customer->outboxEntries()->count());
        $this->onec('GET', '/customers/pending')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $customer->id, 'event' => 'sync_succeeded']);

        // A different answer for the acknowledged entry is refused.
        $this->onec('POST', '/customers/ack', [...$ack, 'external_id' => $this->guid('other')])
            ->assertStatus(207)->assertJsonPath('results.0.errors.0.code', 'already_acknowledged');
    }

    /** AC-I.36 / AC-I.37 */
    public function test_edit_after_delivery_supersedes_the_entry_and_an_old_ack_keeps_the_customer_pending(): void
    {
        $customer = Customer::factory()->syncing()->create();
        $customer->update(['phone' => '+998901111111']);
        $customer->update(['city' => 'Samarkand']);
        $this->assertSame(1, $customer->outboxEntries()->count(), 'Edits before the first poll collapse into one entry.');

        $old = $this->onec('GET', '/customers/pending')->assertJsonPath('data.0.city', 'Samarkand')->json('data.0.outbox_id');

        $customer->update(['phone' => '+998902222222']);
        $this->assertSame(2, $customer->outboxEntries()->count());
        $this->assertNotNull(CustomerOutboxEntry::query()->find($old)->superseded_at);

        $this->onec('POST', '/customers/ack', ['crm_id' => $customer->uuid, 'outbox_id' => $old, 'status' => 'synced', 'external_id' => $this->guid('cp-2')])
            ->assertOk()->assertJsonPath('results.0.result', 'superseded');

        $customer->refresh();
        $this->assertSame(SyncStatus::Pending, $customer->sync_status);
        $this->assertSame($this->guid('cp-2'), $customer->external_id, 'The GUID is stored so 1C updates instead of creating.');

        $this->onec('GET', '/customers/pending')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.phone', '+998902222222')
            ->assertJsonPath('data.0.external_id', $this->guid('cp-2'));
    }

    /** AC-I.35 */
    public function test_failed_ack_stores_the_error_and_resync_requeues(): void
    {
        $owner = User::factory()->sales()->create();
        $customer = Customer::factory()->syncing()->ownedBy($owner)->create();
        $this->onec('GET', '/customers/pending');

        $this->onec('POST', '/customers/ack', ['acks' => [[
            'crm_id' => $customer->uuid, 'status' => 'failed',
            'error' => ['code' => 'validation_failed', 'message' => 'INN checksum invalid', 'fields' => ['tax_id' => ['invalid']]],
        ]]])->assertOk()->assertJsonPath('results.0.result', 'applied');

        $customer->refresh();
        $this->assertSame(SyncStatus::Failed, $customer->sync_status);
        $this->assertSame('INN checksum invalid', $customer->sync_error);
        $this->assertSame(['tax_id' => ['invalid']], $customer->sync_error_fields);

        Livewire::actingAs($owner)->test(Show::class, ['customer' => $customer])
            ->assertSee('INN checksum invalid')
            ->call('resync')
            ->assertHasNoErrors();

        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);
        $this->assertSame(CustomerOutboxEntry::REASON_RESYNC, $customer->outboxEntries()->open()->firstOrFail()->reason);
    }

    /** AC-I.38 */
    public function test_ack_with_a_guid_of_another_customer_is_rejected(): void
    {
        $taken = Customer::factory()->fromOneC()->create();
        $customer = Customer::factory()->syncing()->create();

        $this->onec('POST', '/customers/ack', ['crm_id' => $customer->uuid, 'status' => 'synced', 'external_id' => $taken->external_id])
            ->assertStatus(207)
            ->assertJsonPath('results.0.result', 'rejected')
            ->assertJsonPath('results.0.errors.0.code', 'external_id_taken');

        $this->assertNull($customer->fresh()->external_id);
        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);

        $this->onec('POST', '/customers/ack', ['crm_id' => (string) Str::uuid(), 'status' => 'failed'])
            ->assertStatus(207)->assertJsonPath('results.0.errors.0.code', 'unknown_crm_id');
        $this->onec('POST', '/customers/ack', ['acks' => []])->assertStatus(422);
    }

    /** AC-I.40 */
    public function test_only_shared_field_and_contact_changes_requeue(): void
    {
        $customer = Customer::factory()->syncing()->create();
        $contact = Contact::factory()->create(['partner_id' => $customer->id]);
        $this->onec('GET', '/customers/pending');
        $this->onec('POST', '/customers/ack', ['crm_id' => $customer->uuid, 'status' => 'synced', 'external_id' => $this->guid('cp-3')]);
        $this->assertSame(SyncStatus::Synced, $customer->fresh()->sync_status);

        $customer->refresh()->update(['notes' => 'VIP', 'owner_id' => User::factory()->create()->id, 'payment_terms_days' => 30]);
        $this->assertSame(SyncStatus::Synced, $customer->fresh()->sync_status);
        $this->assertSame(0, $customer->outboxEntries()->open()->count());

        $contact->update(['email' => 'new@mail.uz']);
        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);
        $this->assertSame(CustomerOutboxEntry::REASON_CONTACTS, $customer->outboxEntries()->open()->firstOrFail()->reason);
    }

    public function test_customer_endpoints_share_the_per_token_rate_limit(): void
    {
        config(['onec.inbound.rate_per_minute' => 2]);

        $this->onec('GET', '/customers/pending')->assertOk();
        $this->onec('GET', '/customers/pending')->assertOk();
        $this->onec('GET', '/customers/pending')->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_enabling_the_exchange_queues_existing_not_synced_customers(): void
    {
        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '0']);
        $customer = Customer::factory()->syncing()->create(['sync_error' => 'integration_disabled']);
        $this->assertSame(SyncStatus::NotSynced, $customer->fresh()->sync_status);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Integrations::class)
            ->set('customersEnabled', true)
            ->set('customerPollStaleMinutes', 45)
            ->call('saveCustomerExchange')
            ->assertHasNoErrors();

        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);
        $this->assertSame(45, app(OneCSettings::class)->customerPollStaleMinutes());
    }
}
