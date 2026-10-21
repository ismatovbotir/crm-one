<?php

namespace Tests\Feature\Leads;

use App\Livewire\Leads\Form;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 04 section 16.4: the lead form is step-by-step by default (company and contact, need and
 * items, qualification, review), validates each step, keeps the duplicates panel on every step,
 * saves from any step (Alt+S) and can switch to one page, remembered per user.
 */
class LeadWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
    }

    public function test_steps_validate_before_moving_on_and_review_links_back(): void
    {
        $form = Livewire::actingAs($this->rep)->test(Form::class)
            ->assertSet('step', 1)
            ->assertSeeHtml('aria-current="step"')
            ->assertSee(__('leads.wizard.steps.company'))
            ->assertDontSee(__('leads.sections.bant'))
            ->call('nextStep')
            ->assertHasErrors(['company_name'])
            ->assertSet('step', 1)
            ->set('company_name', 'Chirchiq Agro')
            ->set('contact_phone', '+998 90 123 45 67')
            ->set('tax_id', '12')
            ->call('nextStep')
            ->assertHasErrors(['tax_id'])
            ->set('tax_id', '')
            ->call('nextStep')
            ->assertHasNoErrors()
            ->assertSet('step', 2)
            ->set('est_value', 'abc')
            ->call('nextStep')
            ->assertHasErrors(['est_value'])
            ->assertSet('step', 2)
            ->assertSet('company_name', 'Chirchiq Agro')
            ->set('est_value', '15000000')
            ->set('need', 'Drip irrigation for 40 ha')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->call('nextStep')
            ->assertSet('step', 4)
            ->assertSee(__('leads.wizard.review_title'))
            ->assertSee('Chirchiq Agro')
            ->assertSee('Drip irrigation for 40 ha')
            ->call('goToStep', 2)
            ->assertSet('step', 2)
            ->call('goToStep', 4)
            ->call('save');

        $form->assertHasNoErrors();
        $this->assertSame('Chirchiq Agro', Lead::query()->sole()->company_name);
    }

    public function test_cannot_jump_ahead_and_save_with_errors_returns_to_the_failing_step(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)
            ->call('goToStep', 3)
            ->assertSet('step', 1, 'Steps not reached yet cannot be opened.')
            ->set('company_name', 'Termez Trade')
            ->set('contact_email', 'info@termez.uz')
            ->call('nextStep')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->set('company_name', '')
            ->call('save')
            ->assertHasErrors(['company_name'])
            ->assertSet('step', 1)
            ->assertDispatched('lead-step-changed');
    }

    public function test_alt_s_saves_from_the_first_step_and_save_another_reopens_the_form(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Navoi Mining Supply')
            ->set('contact_phone', '+998 91 000 00 01')
            ->call('save', 'another')
            ->assertHasNoErrors()
            ->assertRedirect(route('leads.create'));

        $this->assertSame(1, Lead::query()->count());
    }

    public function test_duplicates_panel_is_visible_on_every_step(): void
    {
        Customer::factory()->ownedBy($this->rep)->create(['tax_id' => '123456789', 'name' => 'Samarkand Steel']);

        $form = Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Samarkand Steel LLC')
            ->set('tax_id', '123456789')
            ->set('contact_email', 'buyer@samarkand-steel.uz')
            ->assertSee(__('leads.duplicates.title'));

        $form->call('nextStep')->assertSet('step', 2)->assertSee(__('leads.duplicates.title'));
        $form->call('nextStep')->assertSet('step', 3)->assertSee(__('leads.duplicates.title'));
    }

    public function test_single_page_switch_is_remembered_per_user_and_edit_reaches_every_step(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)
            ->call('toggleSinglePage')
            ->assertSet('singlePage', true)
            ->assertSee(__('leads.sections.bant'))
            ->assertDontSeeHtml('data-lead-steps');

        $this->assertTrue($this->rep->fresh()->uiPreference('lead_form_single_page'));
        Livewire::actingAs($this->rep->fresh())->test(Form::class)->assertSet('singlePage', true);

        $other = User::factory()->sales()->create();
        Livewire::actingAs($other)->test(Form::class)->assertSet('singlePage', false);

        $lead = Lead::factory()->create(['owner_id' => $other->id]);
        Livewire::actingAs($other)->test(Form::class, ['lead' => $lead])->call('goToStep', 3)->assertSet('step', 3);
        $this->actingAs(User::factory()->admin()->create())->get(route('leads.create'))->assertOk();
    }
}
