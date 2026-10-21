<?php

namespace Tests\Feature\Customers;

use App\Enums\CustomerOrigin;
use App\Enums\SyncStatus;
use App\Livewire\Partners\Form;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 01 AC-1.1 .. AC-1.5 plus Spec 02 BR-I.17 (tax ID unique incl. archived and 1C duplicates),
 * through the create wizard that /customers/create opens.
 */
class CreateCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function wizard(User $user): Testable
    {
        return Livewire::actingAs($user)->withQueryParams(['role' => 'customer'])->test(Form::class);
    }

    /** AC-1.1 */
    public function test_sales_rep_creates_customer_and_becomes_owner(): void
    {
        $rep = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();

        $this->wizard($rep)
            ->set('form.name', '  Alpha Trade ')
            ->set('form.legal_name', 'Alpha Trade MChJ')
            ->set('form.tax_id', ' 301-234-567 ')
            ->set('form.email', 'Info@Alpha.UZ')
            ->set('form.phone', '+998 (71) 200-10-10')
            ->set('form.owner_id', (string) $manager->id)
            ->call('save')
            ->assertHasErrors(['form.owner_id'])
            ->assertSet('step', 4)
            ->set('form.owner_id', (string) $rep->id)
            ->set('form.contact_first_name', 'Aziz')
            ->set('form.contact_email', 'aziz@alpha.uz')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('tax_id', '301234567')->firstOrFail();
        $this->assertSame('Alpha Trade', $customer->name);
        $this->assertSame('alpha trade', $customer->name_normalized);
        $this->assertSame('info@alpha.uz', $customer->email);
        $this->assertSame($rep->id, $customer->owner_id, 'Sales cannot pick another owner.');
        $this->assertSame(CustomerOrigin::Crm, $customer->origin);
        $this->assertSame(SyncStatus::NotSynced, $customer->sync_status);
        $this->assertNotNull($customer->uuid);
        $this->assertSame('Aziz', $customer->primaryContact->first_name);

        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $customer->id, 'event' => 'created', 'causer_id' => $rep->id]);
    }

    public function test_manager_chooses_owner_and_commercial_terms(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->sales()->create();

        $this->wizard($manager)
            ->set('form.name', 'Beta Foods')
            ->set('form.legal_name', 'Beta Foods MChJ')
            ->set('form.tax_id', '301234568')
            ->set('form.owner_id', (string) $rep->id)
            ->set('form.payment_terms_days', '30')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::query()->where('name', 'Beta Foods')->firstOrFail();
        $this->assertSame($rep->id, $customer->owner_id);
        $this->assertSame(30, $customer->payment_terms_days);
    }

    /** AC-1.2 */
    public function test_existing_tax_id_blocks_creation_with_link(): void
    {
        Customer::factory()->create(['tax_id' => '123456789', 'name' => 'Existing Co']);

        $this->wizard(User::factory()->sales()->create())
            ->set('form.name', 'Another Name')
            ->set('form.legal_name', 'Another Name MChJ')
            ->set('form.tax_id', ' 123-456-789 ')
            ->assertSee('Existing Co')
            ->call('save')
            ->assertHasErrors(['form.tax_id'])
            ->assertSet('step', 2);

        $this->assertSame(1, Customer::withTrashed()->count());
    }

    /** AC-1.3 */
    public function test_archived_tax_id_offers_restore_and_never_duplicates(): void
    {
        Customer::factory()->archived()->create(['tax_id' => '123456789', 'name' => 'Archived Co']);

        $this->wizard(User::factory()->admin()->create())
            ->set('form.name', 'New Co')
            ->set('form.legal_name', 'New Co MChJ')
            ->set('form.tax_id', '123456789')
            ->assertSee(__('customers.restore_hint_admin'))
            ->call('save')
            ->assertHasErrors(['form.tax_id']);

        $this->wizard(User::factory()->sales()->create())
            ->set('form.name', 'New Co')
            ->set('form.legal_name', 'New Co MChJ')
            ->set('form.tax_id', '123456789')
            ->assertSee(__('customers.restore_hint_other'))
            ->call('save')
            ->assertHasErrors(['form.tax_id']);

        $this->assertSame(1, Customer::withTrashed()->count());
    }

    /** AC-I.46: duplicates imported from 1C are allowed, but the CRM never creates another. */
    public function test_tax_id_duplicated_in_1c_still_blocks_crm_creation(): void
    {
        Customer::factory()->fromOneC()->count(2)->create(['tax_id' => '555666777', 'has_duplicate_tax_id' => true]);

        $this->wizard(User::factory()->manager()->create())
            ->set('form.name', 'Third Copy')
            ->set('form.legal_name', 'Third Copy MChJ')
            ->set('form.tax_id', '555666777')
            ->call('save')
            ->assertHasErrors(['form.tax_id']);

        $this->assertSame(2, Customer::query()->where('tax_id', '555666777')->count());
    }

    /** AC-1.4 */
    public function test_similar_name_shows_a_non_blocking_warning(): void
    {
        Customer::factory()->create(['name' => 'Alpha Trade LLC', 'tax_id' => null]);

        $this->wizard(User::factory()->sales()->create())
            ->set('form.name', 'ALPHA TRADE')
            ->assertSeeHtml('data-duplicates')
            ->assertSee('Alpha Trade LLC')
            ->assertSee(__('customers.duplicates.reasons.name'))
            ->set('form.legal_name', 'Alpha Trade 2 MChJ')
            ->set('form.tax_id', '301234569')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Customer::query()->count());
    }

    public function test_same_contact_email_or_phone_warns(): void
    {
        $existing = Customer::factory()->create(['name' => 'Gamma', 'phone' => '+998901234567']);
        $existing->contacts()->create(['first_name' => 'Ann', 'email' => 'ann@gamma.uz']);

        $this->wizard(User::factory()->sales()->create())
            ->set('form.email', 'ANN@gamma.uz')
            ->assertSee('Gamma')
            ->assertSee(__('customers.duplicates.reasons.email'))
            ->set('form.email', '')
            ->set('form.phone', '+998 90 123 45 67')
            ->assertSee(__('customers.duplicates.reasons.phone'));
    }

    /** AC-1.5 */
    public function test_uzbek_tax_id_must_have_nine_digits(): void
    {
        $rep = User::factory()->sales()->create();

        $this->wizard($rep)
            ->set('form.name', 'Short INN')
            ->set('form.legal_name', 'Short INN MChJ')
            ->set('form.tax_id', '12345')
            ->call('save')
            ->assertHasErrors(['form.tax_id']);

        $this->wizard($rep)
            ->set('form.name', 'Foreign Co')
            ->set('form.legal_name', 'Foreign Co TOO')
            ->set('form.country', 'KZ')
            ->set('form.tax_id', 'KZ12345')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_country_is_chosen_from_the_list_and_its_iso_code_is_not_typed(): void
    {
        $this->wizard(User::factory()->sales()->create())
            ->assertSeeHtml('<select wire:model.live="form.country"')
            ->set('form.name', 'Free Typed Country')
            ->set('form.country', 'XX')
            ->call('save')
            ->assertHasErrors(['form.country']);
    }

    public function test_finance_cannot_create_customers(): void
    {
        $this->actingAs(User::factory()->finance()->create());

        $this->get(route('customers.create'))->assertForbidden();
        Livewire::test(Form::class)->assertForbidden();
    }
}
