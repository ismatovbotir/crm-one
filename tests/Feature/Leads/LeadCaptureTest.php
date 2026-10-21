<?php

namespace Tests\Feature\Leads;

use App\Enums\LeadStatus;
use App\Enums\SaleType;
use App\Livewire\Leads\Form;
use App\Livewire\Leads\Show;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadDraft;
use App\Models\LeadFollowUp;
use App\Models\Offer;
use App\Models\User;
use App\Notifications\LeadFollowUpDue;
use App\Services\Leads\LeadScore;
use App\Support\UzbekIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 04 section 16 (lead capture redesign): unified search and prefill from a customer,
 * duplicate detection with override reason, INN and phone checks, score, field policy for the
 * owner, server-side draft, follow-up with reminder, save and add another, save and create offer.
 */
class LeadCaptureTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
    }

    public function test_identifiers_and_score(): void
    {
        $this->assertSame('+998901234567', UzbekIdentifiers::normalizePhone('90 123-45-67'));
        $this->assertSame('+998901234567', UzbekIdentifiers::normalizePhone('+998 (90) 123 45 67'));
        $this->assertSame('+998901234567', UzbekIdentifiers::normalizePhone('0901234567'));
        $this->assertSame('+77011234567', UzbekIdentifiers::normalizePhone('+7 701 123 45 67'));
        $this->assertNull(UzbekIdentifiers::taxIdError('305123456'));
        $this->assertSame('length', UzbekIdentifiers::taxIdError('12345'));
        $this->assertSame('digits', UzbekIdentifiers::taxIdError('30512345A'));
        $this->assertSame('leading_zero', UzbekIdentifiers::taxIdError('012345678'));

        $score = app(LeadScore::class);
        $this->assertSame(0, $score->calculate([]));
        $this->assertSame(100, $score->calculate(['budget_confirmed' => true, 'authority_confirmed' => true, 'need_confirmed' => true, 'timeline_confirmed' => true, 'customer' => true, 'reachable' => true, 'need' => 'x', 'est_value_minor' => 1, 'expected_close_date' => now()->addDays(10)->toDateString(), 'items' => 1, 'priority' => 'high']));
        $this->assertSame(25, $score->calculate(['budget_confirmed' => true, 'customer' => true]));
        $this->assertSame('low', $score->band(25));
        $this->assertSame('high', $score->band(70));
    }

    public function test_live_checks_score_and_saved_values(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Andijon Agro')
            ->set('tax_id', '12345')
            ->assertSee(__('leads.tax_id_errors.length'))
            ->set('tax_id', '307777777')
            ->set('contact_phone', '93 555 44 33')
            ->assertSee(__('leads.phone_saved_as', ['phone' => '+998935554433']))
            ->set('budget_confirmed', true)
            ->set('need', 'Irrigation pumps')
            ->set('priority', 'high')
            ->assertSee('data-lead-score="30"', false)
            ->call('save')
            ->assertHasNoErrors();

        $lead = Lead::query()->sole();
        $this->assertSame('+998935554433', $lead->contact_phone);
        $this->assertSame(30, $lead->score);
        $this->assertSame('high', $lead->priority->value);
    }

    public function test_exact_duplicate_needs_a_reason_and_it_is_audited(): void
    {
        Lead::factory()->create(['contact_phone' => '+998901112233', 'company_name' => 'Old Lead']);

        $form = Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Another company')
            ->set('contact_phone', '90 111 22 33')
            ->assertSee(__('leads.duplicates.override_reason'))
            ->call('save')
            ->assertHasErrors('duplicateOverride');
        $this->assertSame(1, Lead::query()->count());

        $form->set('duplicateOverride', 'Different branch of the same holding')->call('save')->assertHasNoErrors();
        $lead = Lead::query()->latest('id')->first();
        $this->assertSame('Different branch of the same holding', $lead->duplicate_override_reason);
        $this->assertTrue(ActivityLog::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->where('event', 'duplicate_override')->exists());
    }

    public function test_picking_a_customer_prefills_and_shows_context(): void
    {
        $manager = User::factory()->manager()->create();
        $owner = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($owner)->create(['name' => 'Qarshi Neft', 'tax_id' => '301999888', 'sale_type' => SaleType::Retail, 'preferred_locale' => 'ru', 'currency' => 'UZS']);
        $contact = Contact::factory()->create(['partner_id' => $customer->id, 'is_primary' => true]);

        Livewire::actingAs($manager)->test(Form::class)
            ->set('search', '301999')
            ->assertSee('Qarshi Neft')
            ->call('selectCustomer', $customer->id)
            ->assertSet('customerId', $customer->id)
            ->assertSet('contactId', $contact->id)
            ->assertSet('sale_type', 'retail')
            ->assertSet('locale', 'ru')
            ->assertSet('owner_id', $owner->id)
            ->assertSet('source', 'existing_customer')
            ->assertSee('data-customer-context', false)
            ->assertSee(__('leads.context.receivable'));
    }

    public function test_only_assigners_may_set_another_owner(): void
    {
        $colleague = User::factory()->sales()->create();

        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'ACME')->set('contact_phone', '901234567')
            ->set('owner_id', $colleague->id)
            ->call('save')
            ->assertHasErrors('owner_id');
        $this->assertSame(0, Lead::query()->count());

        $manager = User::factory()->manager()->create();
        Livewire::actingAs($manager)->test(Form::class)
            ->set('company_name', 'ACME')->set('contact_phone', '901234567')
            ->set('owner_id', $colleague->id)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame($colleague->id, Lead::query()->sole()->owner_id);
    }

    public function test_draft_autosave_restore_and_discard(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)->call('autosave');
        $this->assertSame(0, LeadDraft::query()->count(), 'An empty form is not saved.');

        Livewire::actingAs($this->rep)->test(Form::class)->set('company_name', 'Draft LLC')->set('need', 'Half typed')->call('autosave');
        $this->assertSame('Draft LLC', LeadDraft::query()->where('user_id', $this->rep->id)->sole()->payload['company_name']);

        Livewire::actingAs($this->rep)->test(Form::class)
            ->assertSee(__('leads.draft.restore'))
            ->call('restoreDraft')
            ->assertSet('company_name', 'Draft LLC')
            ->assertSet('need', 'Half typed')
            ->set('contact_phone', '901234567')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(0, LeadDraft::query()->count(), 'Saving removes the draft.');

        LeadDraft::query()->create(['user_id' => $this->rep->id, 'payload' => ['company_name' => 'X']]);
        Livewire::actingAs($this->rep)->test(Form::class)->call('discardDraft');
        $this->assertSame(0, LeadDraft::query()->count());
    }

    public function test_follow_up_is_created_for_tomorrow_and_reminded_once(): void
    {
        Notification::fake();

        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Follow LLC')->set('contact_phone', '901234567')
            ->call('save')->assertHasNoErrors();

        $task = LeadFollowUp::query()->sole();
        $this->assertSame($this->rep->id, $task->assignee_id);
        $this->assertSame(CarbonImmutable::tomorrow((string) config('offers.timezone'))->toDateString().' 10:00', $task->due_at->format('Y-m-d H:i'));

        $this->travelTo($task->due_at->addMinute());
        $this->artisan('leads:remind')->assertSuccessful();
        $this->artisan('leads:remind')->assertSuccessful();
        Notification::assertSentToTimes($this->rep, LeadFollowUpDue::class, 1);
    }

    public function test_save_and_add_another_and_save_and_create_offer(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'First LLC')->set('contact_phone', '901234567')->set('followUp', false)
            ->call('save', 'another')
            ->assertRedirect(route('leads.create'));

        $customer = Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS']);
        $contact = Contact::factory()->create(['partner_id' => $customer->id, 'is_primary' => true]);
        $item = $this->pricedItem('100.00');

        $component = Livewire::actingAs($this->rep)->test(Form::class)
            ->call('selectCustomer', $customer->id)
            ->set('need', 'Two units')
            ->set('budget_confirmed', true)->set('authority_confirmed', true)->set('need_confirmed', true)->set('timeline_confirmed', true)
            ->call('addItem', $item->id)
            ->call('save', 'offer');

        // Offers go only to partners: the lead is saved and the conversion dialog opens.
        $lead = Lead::query()->where('customer_id', $customer->id)->sole();
        $component->assertRedirect(route('leads.show', ['lead' => $lead, 'panel' => 'convert']));
        $this->assertSame(0, Offer::query()->count());
        $this->assertSame($contact->id, $lead->contact_id);
        Livewire::actingAs($this->rep)->withQueryParams(['panel' => 'convert'])->test(Show::class, ['lead' => $lead])->assertSet('panel', 'convert');
    }

    public function test_save_and_create_offer_stops_at_the_gate(): void
    {
        $customer = Customer::factory()->ownedBy($this->rep)->create();
        Contact::factory()->create(['partner_id' => $customer->id, 'is_primary' => true]);

        $component = Livewire::actingAs($this->rep)->test(Form::class)->call('selectCustomer', $customer->id)->set('need', 'x')->call('save', 'offer');

        $lead = Lead::query()->sole();
        $component->assertRedirect(route('leads.show', ['lead' => $lead, 'panel' => 'convert']));
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame(0, Offer::query()->count());
    }
}
