<?php

namespace Tests\Feature\Api\OneC;

use App\Enums\CustomerOrigin;
use App\Enums\IntegrationAbility;
use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\InboundBatch;
use App\Models\Setting;
use App\Models\User;
use App\Services\OneC\OneCSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 rev. 3 AC-I.41..I.47: 1C pushes its counterparties with POST /customers.
 */
class CustomerInboundTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1']);
        $this->manager = User::factory()->manager()->create(['email' => 'boss@crm.test']);
        $this->issueToken([IntegrationAbility::CustomersRead->value, IntegrationAbility::CustomersWrite->value]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function counterparty(string $seed, array $overrides = []): array
    {
        return [
            'id' => $this->guid('cp-'.$seed), 'code' => '0000'.$seed, 'crm_id' => null,
            'legal_type' => 'legal_entity', 'name' => 'Counterparty '.$seed, 'full_name' => '"CP '.$seed.'" MChJ',
            'tax_id' => '40000000'.substr($seed, -1), 'country' => 'UZ', 'city' => 'Tashkent', 'address' => 'Street 1',
            'phone' => '+998901234567', 'email' => 'cp'.$seed.'@mail.uz',
            'manager' => ['name' => 'Boss', 'email' => 'boss@crm.test'],
            'contacts' => [['id' => $this->guid('ct-'.$seed), 'first_name' => 'Aziz', 'last_name' => 'K', 'email' => 'aziz'.$seed.'@mail.uz', 'is_deleted' => false]],
            'is_deleted' => false, 'merged_into_id' => null, 'version' => 'AAAB', 'updated_at' => '2026-10-18T10:30:00+05:00',
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return array<string, mixed>
     */
    protected function push(array $customers, ?string $batchId = null): array
    {
        return ['batch_id' => $batchId ?? (string) Str::uuid(), 'sent_at' => '2026-10-18T10:40:00+05:00', 'customers' => $customers];
    }

    /** AC-I.44 */
    public function test_new_counterparty_is_created_with_contacts_and_owner_from_the_manager_email(): void
    {
        $this->onec('POST', '/customers', $this->push([$this->counterparty('1')]))
            ->assertOk()
            ->assertJsonPath('results.0.result', 'created');

        $customer = Customer::query()->where('external_id', $this->guid('cp-1'))->firstOrFail();
        $this->assertSame(CustomerOrigin::Onec, $customer->origin);
        $this->assertSame(SyncStatus::Synced, $customer->sync_status);
        $this->assertSame($this->manager->id, $customer->owner_id);
        $this->assertSame('00001', $customer->external_code);
        $this->assertSame('Aziz', $customer->primaryContact->first_name);
        $this->assertSame(0, CustomerOutboxEntry::query()->count(), 'Data from 1C is never queued back to 1C.');

        // Unknown manager e-mail: falls back to the first manager.
        $this->onec('POST', '/customers', $this->push([$this->counterparty('2', ['manager' => ['email' => 'nobody@x.uz']])]))->assertOk();
        $this->assertSame($this->manager->id, Customer::query()->where('external_id', $this->guid('cp-2'))->value('owner_id'));
    }

    /** AC-I.41 */
    public function test_unknown_guid_links_the_single_unlinked_crm_customer_with_the_same_inn(): void
    {
        $crm = Customer::factory()->syncing()->create(['tax_id' => '400000003', 'name' => 'Old name']);

        $this->onec('POST', '/customers', $this->push([$this->counterparty('3')]))
            ->assertOk()
            ->assertJsonPath('results.0.result', 'linked')
            ->assertJsonPath('results.0.crm_id', $crm->uuid);

        $crm->refresh();
        $this->assertSame($this->guid('cp-3'), $crm->external_id);
        $this->assertSame('Counterparty 3', $crm->name, '1C values win.');
        $this->assertSame(SyncStatus::Synced, $crm->sync_status);
        $this->assertSame(0, $crm->outboxEntries()->open()->count());
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $crm->id, 'event' => 'sync_linked_existing']);
    }

    public function test_inn_matching_several_unlinked_customers_is_reported_as_conflict(): void
    {
        // Only one active CRM customer may hold a tax ID (DB unique key); an archived one still counts as a candidate.
        $a = Customer::factory()->syncing()->create(['tax_id' => '400000004']);
        $b = Customer::factory()->syncing()->archived()->create(['tax_id' => '400000004']);

        $this->onec('POST', '/customers', $this->push([$this->counterparty('4')]))
            ->assertStatus(207)
            ->assertJsonPath('results.0.result', 'conflict')
            ->assertJsonPath('results.0.candidates', [$a->uuid, $b->uuid]);

        $this->assertSame(0, Customer::query()->whereNotNull('external_id')->count());
    }

    /** AC-I.46 */
    /** Spec 02 rev. 4: the tax ID is the only key; a new GUID for the same tax ID updates the same partner. */
    public function test_matching_uses_the_tax_id_only_so_duplicates_cannot_appear(): void
    {
        $linked = Customer::factory()->fromOneC()->create(['tax_id' => '400000005']);

        $this->onec('POST', '/customers', $this->push([$this->counterparty('5')]))->assertOk()->assertJsonPath('results.0.result', 'linked');

        $this->assertSame(1, Customer::withTrashed()->where('tax_id', '400000005')->count());
        $this->assertSame($this->guid('cp-5'), $linked->fresh()->external_id, 'The GUID is stored as information.');
        $this->assertTrue($linked->fresh()->sync_with_1c);

        $noTax = $this->counterparty('5b');
        $noTax['tax_id'] = null;
        $this->onec('POST', '/customers', $this->push([$noTax]))->assertOk()->assertJsonPath('results.0.result', 'skipped')->assertJsonPath('results.0.errors.0.code', 'tax_id_missing');

        Setting::putMany([OneCSettings::ALLOW_CREATE_KEY => '0']);
        $this->onec('POST', '/customers', $this->push([$this->counterparty('5c')]))->assertOk()->assertJsonPath('results.0.result', 'skipped')->assertJsonPath('results.0.errors.0.code', 'create_disabled');
    }

    /** AC-I.42 */
    public function test_1c_wins_over_a_pending_crm_edit_and_the_replaced_values_are_logged(): void
    {
        $customer = Customer::factory()->fromOneC()->create(['external_id' => $this->guid('cp-6'), 'tax_id' => '400000006', 'city' => 'Bukhara']);
        $customer->update(['city' => 'Khiva']);
        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);

        $this->onec('POST', '/customers', $this->push([$this->counterparty('6')]))->assertOk()->assertJsonPath('results.0.result', 'updated');

        $customer->refresh();
        $this->assertSame('Tashkent', $customer->city);
        $this->assertSame(SyncStatus::Synced, $customer->sync_status);
        $this->assertSame(CustomerOutboxEntry::SUPERSEDED_BY_1C, $customer->outboxEntries()->firstOrFail()->superseded_reason);

        $log = $customer->activities()->where('event', 'sync_conflict_1c_won')->firstOrFail();
        $this->assertSame('Khiva', $log->properties['old']['city']);
        $this->assertSame('Tashkent', $log->properties['new']['city']);
    }

    /** AC-I.45 / unchanged */
    public function test_older_records_are_stale_and_identical_records_unchanged(): void
    {
        $this->onec('POST', '/customers', $this->push([$this->counterparty('7')]))->assertOk();

        $this->onec('POST', '/customers', $this->push([$this->counterparty('7')]))->assertJsonPath('results.0.result', 'unchanged');
        $this->onec('POST', '/customers', $this->push([$this->counterparty('7', ['name' => 'Older', 'updated_at' => '2026-10-17T10:00:00+05:00'])]))
            ->assertJsonPath('results.0.result', 'stale');

        $this->assertSame('Counterparty 7', Customer::query()->where('external_id', $this->guid('cp-7'))->value('name'));
    }

    /** AC-I.47 */
    public function test_deletion_mark_and_individuals(): void
    {
        $this->onec('POST', '/customers', $this->push([
            $this->counterparty('8', ['is_deleted' => true, 'merged_into_id' => $this->guid('cp-9')]),
            $this->counterparty('9', ['legal_type' => 'individual']),
        ]))->assertOk()
            ->assertJsonPath('results.0.result', 'created')
            ->assertJsonPath('results.1.result', 'skipped');

        $deleted = Customer::query()->where('external_id', $this->guid('cp-8'))->firstOrFail();
        $this->assertTrue($deleted->isDeletedInOneC());
        $this->assertSame($this->guid('cp-9'), $deleted->merged_into_external_id);
        $this->assertNull(Customer::query()->where('external_id', $this->guid('cp-9'))->first());
    }

    /** AC-I.43 */
    public function test_push_is_idempotent_by_batch_id(): void
    {
        $body = $this->push([$this->counterparty('1')]);

        $first = $this->onec('POST', '/customers', $body)->assertOk();
        $second = $this->onec('POST', '/customers', $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        // JSON read back from the database: key order is not guaranteed on MySQL.
        $this->assertEquals($first->json('results'), $second->json('results'));
        $this->assertSame(1, Customer::query()->count());

        $this->onec('POST', '/customers', [...$body, 'customers' => [$this->counterparty('2')]])
            ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_key_reused');

        $batch = InboundBatch::query()->where('batch_id', $body['batch_id'])->sole();
        $this->assertSame('customers', $batch->endpoint);
        $this->assertNull($batch->payloadRow, 'Customer pushes hold personal data: the body is not stored.');
    }

    public function test_invalid_envelopes_and_records(): void
    {
        $this->onec('POST', '/customers', ['customers' => []])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->onec('POST', '/customers', $this->push([$this->counterparty('1', ['id' => 'nope', 'is_deleted' => 'no'])]))
            ->assertStatus(207)
            ->assertJsonPath('results.0.result', 'failed')
            ->assertJsonPath('results.0.errors.0.field', 'id');

        $this->issueToken([IntegrationAbility::CustomersRead->value]);
        $this->onec('POST', '/customers', $this->push([$this->counterparty('1')]))->assertForbidden();
    }
}
