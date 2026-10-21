<?php

namespace Tests\Feature\Partners;

use App\Actions\Offers\CreateOffer;
use App\Actions\Partners\ChangePartnerRoles;
use App\Enums\CounterpartyStatus;
use App\Enums\DepartmentRole;
use App\Livewire\Partners\Form;
use App\Livewire\Partners\Index;
use App\Livewire\Partners\Roles;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\User;
use App\Services\OneC\OneCSettings;
use App\Services\Partners\Exceptions\PartnerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 03 section 24: the Partner model (table `partners`), the unified "New partner" form with
 * permission-limited roles, the tax-ID duplicate rule, "Edit roles" with dependency checks, the
 * Partners list per role, the 1C customer outbox only for the customer role, legacy morph alias.
 */
class PartnerTest extends TestCase
{
    use RefreshDatabase;

    private function purchaser(): User
    {
        return User::factory()->staff()->inDepartment('purchase', DepartmentRole::Specialist)->create();
    }

    public function test_schema_is_renamed_and_old_links_keep_working(): void
    {
        $this->assertTrue(Schema::hasTable('partners'));
        $this->assertFalse(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasColumn('contacts', 'partner_id'));
        $this->assertTrue(Schema::hasColumn('counterparties', 'partner_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'customer_id'), 'Role-specific links keep customer_id.');

        $customer = Customer::factory()->create();
        $this->assertSame('partner', $customer->getMorphClass());
        $legacy = ActivityLog::query()->create(['subject_type' => 'customer', 'subject_id' => $customer->id, 'event' => 'legacy', 'created_at' => now()]);
        $this->assertSame($customer->id, $legacy->subject?->id, 'The old morph alias still resolves.');
    }

    public function test_manager_creates_one_partner_with_all_roles(): void
    {
        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1']);
        $manager = User::factory()->manager()->create();

        Livewire::actingAs($manager)->test(Form::class)
            ->assertSee(__('partners.roles.customer'))->assertSee(__('partners.roles.carrier'))
            ->set('roles', ['customer', 'supplier', 'carrier'])
            ->set('form.name', 'Delta Group')->set('form.legal_name', 'Delta Group MChJ')
            ->set('form.tax_id', '305555555')
            ->set('form.contact_first_name', 'Aziz')
            ->set('form.contact_email', 'aziz@delta.uz')
            ->set('form.fleet_size', '12')
            ->set('syncWith1c', true)
            ->call('save')
            ->assertHasNoErrors();

        $partner = Partner::query()->where('tax_id', '305555555')->sole();
        $this->assertSame(['customer', 'supplier', 'carrier'], $partner->roles());
        $profile = Counterparty::query()->where('partner_id', $partner->id)->sole();
        $this->assertSame(CounterpartyStatus::Prospect, $profile->status);
        $this->assertSame(12, $profile->fleet_size);
        $this->assertSame(1, Contact::query()->where('partner_id', $partner->id)->count());
        $this->assertSame(1, CustomerOutboxEntry::query()->where('customer_id', $partner->id)->count(), 'Customer role joins the 1C customer exchange.');
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $partner->id, 'event' => 'partner_created']);
    }

    public function test_role_choices_follow_permissions_and_suppliers_stay_out_of_1c(): void
    {
        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1']);
        $sales = User::factory()->sales()->create();
        Livewire::actingAs($sales)->test(Form::class)
            ->assertSet('roles', ['customer'])
            ->assertDontSee('data-role-option="supplier"', false);
        $this->actingAs($sales)->get(route('customers.create'))->assertOk()->assertSee('data-role-option="customer"', false);

        $purchaser = $this->purchaser();
        Livewire::actingAs($purchaser)->test(Form::class)
            ->assertDontSee('data-role-option="customer"', false)
            ->set('roles', ['customer', 'supplier'])
            ->set('form.name', 'Steel Supply')->set('form.legal_name', 'Steel Supply MChJ')
            ->set('form.tax_id', '306666666')
            ->call('save')
            ->assertHasNoErrors();

        $partner = Partner::query()->where('tax_id', '306666666')->sole();
        $this->assertSame(['supplier'], $partner->roles(), 'A role the user may not create is ignored.');
        $this->assertNull(Customer::query()->find($partner->id));
        $this->assertSame(0, CustomerOutboxEntry::query()->where('customer_id', $partner->id)->count());
        $this->actingAs($purchaser)->get(route('partners.create', ['role' => 'carrier']))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'staff']))->get(route('partners.create'))->assertForbidden();
    }

    public function test_existing_tax_id_is_refused_and_the_role_can_be_added_instead(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create(['name' => 'Alfa Trade', 'tax_id' => '301111111']);

        Livewire::actingAs($manager)->test(Form::class)
            ->set('roles', ['supplier'])
            ->set('form.name', 'Alfa Trade LLC')->set('form.legal_name', 'Alfa Trade LLC MChJ')
            ->set('form.tax_id', '301 111 111')
            ->assertSee('Alfa Trade')
            ->call('save')
            ->assertHasErrors('form.tax_id')
            ->call('addRolesToExisting');

        $this->assertSame(1, Partner::withTrashed()->where('tax_id', '301111111')->count());
        $this->assertSame(['customer', 'supplier'], Partner::query()->find($customer->id)->roles());
    }

    public function test_roles_in_use_cannot_be_removed(): void
    {
        $manager = User::factory()->manager()->create();
        $customer = Customer::factory()->create(['tax_id' => '302222222']);
        $partner = Partner::query()->findOrFail($customer->id);
        $change = app(ChangePartnerRoles::class);

        $partner = $change->handle($partner, ['customer', 'carrier'], $manager);
        $this->assertSame(['customer', 'carrier'], $partner->roles());

        app(CreateOffer::class)->handle($manager, $customer);

        try {
            $change->handle($partner, ['carrier'], $manager);
            $this->fail('A customer with offers keeps the role.');
        } catch (PartnerException $exception) {
            $this->assertSame('role_in_use', $exception->key);
        }

        $partner = $change->handle($partner->fresh(), ['customer'], $manager);
        $this->assertSame(['customer'], $partner->roles(), 'An unused carrier role is removed.');
        $this->assertTrue(Counterparty::withTrashed()->where('partner_id', $partner->id)->sole()->trashed(), 'The profile is archived, not deleted.');

        try {
            $change->handle($partner, [], $manager);
            $this->fail('At least one role.');
        } catch (PartnerException $exception) {
            $this->assertSame('role_required', $exception->key);
        }

        $sales = User::factory()->sales()->create();

        try {
            $change->handle($partner, ['customer', 'supplier'], $sales);
            $this->fail('Sales may not add the supplier role.');
        } catch (PartnerException $exception) {
            $this->assertSame('role_not_allowed', $exception->key);
        }

        Livewire::actingAs($manager)->test(Roles::class, ['partnerId' => $partner->id])
            ->call('edit')->set('roles', ['customer', 'supplier'])->call('save')->assertHasNoErrors();
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $partner->id, 'event' => 'roles_changed']);
    }

    public function test_partners_list_shows_each_user_the_roles_they_may_see(): void
    {
        $owner = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($owner)->create(['name' => 'Customer Co']);
        Counterparty::factory()->create(['name' => 'Supplier Only Co', 'tax_id' => '303333333']);

        Livewire::actingAs(User::factory()->manager()->create())->test(Index::class)->assertSee('Customer Co')->assertSee('Supplier Only Co');
        Livewire::actingAs(User::factory()->admin()->create())->test(Index::class)->assertSee('Customer Co')->assertSee('Supplier Only Co');
        Livewire::actingAs($owner)->test(Index::class)->assertSee('Customer Co')->assertDontSee('Supplier Only Co');
        Livewire::actingAs($this->purchaser())->test(Index::class)->assertSee('Supplier Only Co');

        $this->actingAs($owner)->get(route('partners.show', $customer->id))->assertRedirect(route('customers.show', $customer->id));
        $this->actingAs($owner)->get(route('partners.index'))->assertOk();
    }
}
