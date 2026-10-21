<?php

namespace Tests\Feature\Customers;

use App\Actions\Counterparties\ManageCounterparty;
use App\Livewire\Counterparties\Index as CounterpartiesIndex;
use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Partners\Form as CustomerForm;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\Partner;
use App\Models\PortalMembership;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 03 section 22: one organizations table with roles. The data migration merges by tax ID and
 * logs conflicts; customer screens and the 1C customer exchange only see customers; supplier and
 * customer roles are added to an existing organization instead of duplicating it; contacts are one
 * table; identity is mastered by the organization.
 */
class OrganizationRolesTest extends TestCase
{
    use RefreshDatabase;

    private function legacyCounterparty(array $attributes): int
    {
        return (int) DB::table('counterparties')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => 'Supplier', 'is_supplier' => true, 'is_carrier' => false, 'status' => 'qualified',
            'has_tir' => false, 'has_adr' => false, 'created_at' => now(), 'updated_at' => now(), ...$attributes,
        ]);
    }

    public function test_data_migration_merges_by_tax_id_logs_conflicts_and_copies_contacts(): void
    {
        // Replays archived (pre-squash) migrations written for SQLite dev data; MySQL runs skip it.
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Archived data migration replay runs on SQLite only.');
        }

        $owner = User::factory()->manager()->create();
        $customer = Customer::factory()->create(['name' => 'Alfa Trade', 'tax_id' => '301111111', 'city' => 'Tashkent']);
        Contact::query()->create(['partner_id' => $customer->id, 'first_name' => 'Old', 'is_primary' => true]);

        // Replay the merge on the schema it was written for (before the Partner rename, section 24).
        $partners = require database_path('migrations_archive_pre_squash/2026_10_19_101142_rename_customers_to_partners.php');
        $migration = require database_path('migrations_archive_pre_squash/2026_10_19_063226_merge_counterparties_into_organizations.php');
        $partners->down();
        $migration->down();
        $merged = $this->legacyCounterparty(['name' => 'Alfa Trade LLC', 'tax_id' => '301 111 111', 'city' => 'Samarkand', 'is_carrier' => true]);
        $twinA = $this->legacyCounterparty(['name' => 'Beta', 'tax_id' => '302222222']);
        $twinB = $this->legacyCounterparty(['name' => 'Beta Logistics', 'tax_id' => '302222222', 'is_supplier' => false, 'is_carrier' => true]);
        $noTax = $this->legacyCounterparty(['name' => 'Gamma', 'owner_id' => $owner->id]);
        DB::table('counterparty_contacts')->insert([
            ['counterparty_id' => $merged, 'name' => 'Ali Valiyev', 'email' => 'ALI@alfa.uz', 'telegram' => '@ali', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['counterparty_id' => $noTax, 'name' => 'Gulnora', 'email' => null, 'telegram' => null, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $before = DB::table('customers')->count();

        $migration->up();
        $partners->up();

        $this->assertSame($customer->id, Counterparty::query()->find($merged)->partner_id);
        $organization = Partner::query()->find($customer->id);
        $this->assertTrue($organization->is_customer && $organization->is_supplier && $organization->is_carrier);
        $this->assertSame('Alfa Trade', $organization->name, 'Customer data is never overwritten.');
        $log = DB::table('organization_merges')->where('counterparty_id', $merged)->first();
        $this->assertSame('merged', $log->action);
        $this->assertEquals(['organization' => 'Tashkent', 'counterparty' => 'Samarkand'], json_decode($log->conflicts, true)['city']);

        $this->assertSame(Counterparty::query()->find($twinA)->partner_id, Counterparty::query()->find($twinB)->partner_id, 'Same tax ID, one organization.');
        $this->assertSame(2, Partner::withTrashed()->count() - $before, 'Beta and Gamma are new organizations.');
        $gamma = Partner::query()->find(Counterparty::query()->find($noTax)->partner_id);
        $this->assertFalse($gamma->is_customer);
        $this->assertSame($owner->id, $gamma->owner_id);

        $ali = Contact::query()->where('legacy_counterparty_contact_id', '!=', null)->where('first_name', 'Ali')->sole();
        $this->assertSame([$customer->id, 'Valiyev', 'ali@alfa.uz', 'ali', false], [$ali->partner_id, $ali->last_name, $ali->email, $ali->telegram_username, $ali->is_primary]);
        $this->assertTrue(Contact::query()->where('first_name', 'Gulnora')->sole()->is_primary);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        $this->artisan('crm:organization-merges --conflicts')->assertSuccessful()->expectsOutputToContain('Samarkand');
    }

    public function test_supplier_only_organizations_stay_out_of_customer_screens_and_1c(): void
    {
        $sales = User::factory()->sales()->create();
        $supplier = Counterparty::factory()->create(['name' => 'Delta Supply', 'tax_id' => '303333333']);
        $organization = $supplier->organization;

        $this->assertFalse($organization->is_customer);
        $this->assertTrue($organization->is_supplier);
        $this->assertNull(Customer::query()->find($organization->id));
        $this->assertSame(0, CustomerOutboxEntry::query()->where('customer_id', $organization->id)->count(), 'Never offered to 1C as a customer.');
        $this->actingAs($sales)->get(route('customers.index'))->assertOk()->assertDontSee('Delta Supply');
        $this->actingAs($sales)->get(route('customers.show', $organization->id))->assertNotFound();
    }

    public function test_customer_gets_supplier_role_instead_of_a_duplicate_and_badges_show(): void
    {
        $purchaser = User::factory()->create(['role' => 'staff']);
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create(['name' => 'Epsilon', 'tax_id' => '304444444']);

        try {
            app(ManageCounterparty::class)->save(null, ['name' => 'Epsilon dup', 'tax_id' => '304444444', 'is_supplier' => true, 'is_carrier' => false], $manager);
            $this->fail('A duplicate organization must be refused.');
        } catch (TenderActionException $exception) {
            $this->assertStringContainsString('Epsilon', $exception->getMessage());
        }

        Livewire::actingAs($manager)->test(CounterpartiesIndex::class)
            ->set('form.name', 'Epsilon dup')->set('form.tax_id', '304444444')->set('form.is_supplier', true)
            ->call('create')->assertHasErrors('form.tax_id')
            ->call('addRoleToExisting');

        $profile = Counterparty::query()->where('partner_id', $customer->id)->sole();
        $this->assertSame('Epsilon', $profile->name, 'The profile copies the organization identity.');
        $this->assertTrue(Partner::query()->find($customer->id)->is_supplier);
        $this->assertSame(1, Partner::query()->where('tax_id', '304444444')->count());

        $this->actingAs($manager)->get(route('customers.show', $customer))->assertSee(__('organizations.also_supplier'));
        $this->actingAs($manager)->get(route('counterparties.show', $profile))->assertSee(__('organizations.also_customer'))->assertSee(__('organizations.identity_on_customer_card'));

        $customer->update(['name' => 'Epsilon Group']);
        $this->assertSame('Epsilon Group', $profile->fresh()->name, 'Identity changes reach the supplier profile.');
        app(ManageCounterparty::class)->save($profile->fresh(), ['name' => 'Hacked', 'is_supplier' => true, 'is_carrier' => false], $manager);
        $this->assertSame('Epsilon Group', Partner::query()->find($customer->id)->name, 'Customer identity is edited on the customer card only.');
    }

    public function test_supplier_gets_customer_role_and_is_offered_to_1c(): void
    {
        config(['onec.enabled' => true]);
        $manager = User::factory()->manager()->create();
        $supplier = Counterparty::factory()->create(['owner_id' => $manager->id, 'name' => 'Zeta', 'tax_id' => '305555555']);
        $component = Livewire::actingAs($manager)->withQueryParams(['role' => 'customer'])->test(CustomerForm::class)
            ->set('form.name', 'Zeta customer')->set('form.legal_name', 'Zeta customer MChJ')->set('form.tax_id', '305555555')
            ->call('save')->assertHasErrors('form.tax_id')
            ->assertSee('Zeta');
        $component->call('addRolesToExisting')->assertRedirect();

        $customer = Customer::query()->findOrFail($supplier->partner_id);
        $this->assertSame('Zeta', $customer->name);
        $this->assertFalse(CustomerOutboxEntry::query()->where('customer_id', $customer->id)->exists(), 'Spec 02 rev. 4: not offered until "Sync with 1C" is on.');
        $customer->update(['sync_with_1c' => true]);
        $this->assertTrue(CustomerOutboxEntry::query()->where('customer_id', $customer->id)->where('reason', CustomerOutboxEntry::REASON_CREATED)->exists());
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $customer->id, 'event' => 'role_added']);
    }

    public function test_contacts_are_one_table_and_supplier_contacts_never_queue_1c(): void
    {
        $manager = User::factory()->manager()->create();
        $supplier = Counterparty::factory()->create();
        $contact = app(ManageCounterparty::class)->saveContact($supplier, null, ['name' => 'Rustam Karimov', 'email' => 'R@delta.uz', 'telegram' => '@rustam', 'is_primary' => true]);

        $this->assertSame([$supplier->partner_id, 'Rustam', 'Karimov', 'rustam'], [$contact->partner_id, $contact->first_name, $contact->last_name, $contact->telegram_username]);
        $this->assertSame('Rustam Karimov', $supplier->fresh()->contacts->first()->name);
        $this->assertSame(0, CustomerOutboxEntry::query()->count());

        $membership = PortalMembership::query()->create(['user_id' => User::factory()->portal()->create()->id, 'counterparty_id' => $supplier->id, 'roles' => ['viewer'], 'is_active' => true]);
        $this->assertSame($supplier->partner_id, $membership->partner_id);

        Livewire::actingAs($manager)->test(CustomerShow::class, ['customer' => Customer::factory()->create()])->call('addSupplierRole', 'carrier')->assertHasNoErrors();
        $this->assertSame(2, Counterparty::query()->count());
    }
}
