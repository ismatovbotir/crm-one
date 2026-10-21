<?php

namespace Tests\Feature\Partners;

use App\Actions\Leads\SaveLead;
use App\Livewire\Partners\Form;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "New partner" is a five-step wizard: Next validates only the current step, earlier steps can be
 * revisited, later ones cannot be skipped to, and Create validates everything.
 */
class PartnerWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_starts_on_the_first_step_and_next_needs_a_role(): void
    {
        Livewire::actingAs(User::factory()->manager()->create())->test(Form::class)
            ->assertSet('step', 1)
            ->assertSeeHtml('data-step="roles" data-state="current"')
            ->call('next')
            ->assertHasErrors(['roles'])
            ->assertSet('step', 1)
            ->set('roles', ['customer'])
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->assertSet('furthest', 2);
    }

    public function test_next_validates_only_the_fields_of_the_current_step(): void
    {
        Livewire::actingAs(User::factory()->manager()->create())->test(Form::class)
            ->set('roles', ['customer'])
            ->call('next')
            ->call('next')
            ->assertHasErrors(['form.name'])
            ->assertHasNoErrors(['form.country', 'form.contact_first_name'])
            ->assertSet('step', 2)
            ->set('form.name', 'Delta Group')
            ->set('form.legal_name', 'Delta Group MChJ')
            ->set('form.tax_id', '12345')
            ->call('next')
            ->assertHasErrors(['form.tax_id'])
            ->assertSet('step', 2)
            ->set('form.tax_id', '305555555')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 3);
    }

    public function test_earlier_steps_can_be_revisited_but_later_ones_cannot_be_skipped_to(): void
    {
        Livewire::actingAs(User::factory()->manager()->create())->test(Form::class)
            ->set('roles', ['customer'])
            ->call('next')
            ->call('goToStep', 4)
            ->assertSet('step', 2)
            ->call('back')
            ->assertSet('step', 1)
            ->call('goToStep', 2)
            ->assertSet('step', 2)
            ->assertSet('furthest', 2);
    }

    public function test_the_step_cannot_be_forged_from_the_browser(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs(User::factory()->manager()->create())->test(Form::class)->set('step', 5);
    }

    public function test_create_returns_to_the_first_step_with_an_error(): void
    {
        Livewire::actingAs(User::factory()->manager()->create())->test(Form::class)
            ->set('roles', ['customer'])
            ->set('form.currency', 'ZZZ')
            ->set('form.contact_email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.currency', 'form.contact_email'])
            ->assertSet('step', 2);
    }

    public function test_walking_all_steps_creates_the_partner(): void
    {
        $this->assertSame(0, Partner::query()->count());

        Livewire::actingAs(User::factory()->manager()->create())->test(Form::class)
            ->set('roles', ['customer'])
            ->call('submit')
            ->set('form.name', 'Delta Group')
            ->set('form.legal_name', 'Delta Group MChJ')
            ->set('form.tax_id', '305555555')
            ->call('submit')
            ->set('form.country', 'KZ')
            ->call('submit')
            ->assertSet('step', 4)
            ->call('submit')
            ->assertSet('step', 5)
            ->assertSeeHtml('data-action="create-partner"')
            ->set('form.contact_first_name', 'Aziz')
            ->call('submit')
            ->assertHasNoErrors();

        $partner = Partner::query()->where('tax_id', '305555555')->sole();
        $this->assertSame('KZ', $partner->country);
        $this->assertSame(1, $partner->contacts()->count());
    }

    public function test_create_company_from_a_lead_prefills_the_wizard_and_links_the_lead(): void
    {
        $rep = User::factory()->sales()->create();
        $lead = app(SaveLead::class)->handle($rep, [
            'source' => 'phone', 'company_name' => 'Buxoro Textile', 'tax_id' => '305123456', 'contact_name' => 'Dilnoza Rahimova',
            'contact_email' => 'dilnoza@buxoro.uz', 'currency' => 'UZS',
        ], []);

        $this->actingAs($rep)->get(route('customers.create', ['lead' => $lead->id]))
            ->assertOk()
            ->assertSee('Buxoro Textile');

        Livewire::actingAs($rep)->withQueryParams(['role' => 'customer', 'lead' => $lead->id])->test(Form::class)
            ->assertSet('form.name', 'Buxoro Textile')
            ->assertSet('form.contact_first_name', 'Dilnoza')
            ->set('form.legal_name', 'Buxoro Textile MChJ')
            ->call('submit')
            ->call('submit')
            ->call('submit')
            ->call('submit')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('leads.show', $lead));

        $lead->refresh();
        $this->assertSame('Buxoro Textile', $lead->customer?->name);
        $this->assertSame('dilnoza@buxoro.uz', $lead->contact?->email);
    }
}
