<?php

namespace Tests\Feature\Leads;

use App\Actions\Leads\ConvertLeadToCustomer;
use App\Actions\Leads\LeadWorkflow;
use App\Actions\Leads\SaveLead;
use App\Enums\DepartmentRole;
use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Enums\OfferStatus;
use App\Livewire\Leads\Form;
use App\Livewire\Leads\Index;
use App\Livewire\Leads\Show;
use App\Livewire\Partners\Form as PartnerForm;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 04 AC-1..AC-8: capture and numbering, duplicates, qualification gate, conversion to an
 * offer (prefill, idempotency, skipped items), close/reopen rules, transition map, permissions,
 * stale filter, create customer from lead.
 */
class LeadWorkflowTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
    }

    private function qualifiedLead(?User $owner = null): Lead
    {
        $owner ??= $this->rep;
        $customer = Customer::factory()->ownedBy($owner)->create(['currency' => 'UZS']);
        $lead = Lead::factory()->ownedBy($owner)->qualifiable($customer)->create();
        app(LeadWorkflow::class)->qualify($lead, $owner);

        return $lead->refresh();
    }

    /** AC-1 */
    public function test_rep_captures_a_lead_with_number_history_and_owner(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Toshkent Qurilish MChJ')
            ->set('contact_name', 'Aziz Karimov')
            ->set('contact_phone', '+998 90 123 45 67')
            ->set('source', 'exhibition')
            ->set('need', '20 servers for a data centre')
            ->set('est_value', '150000000')
            ->call('save')
            ->assertHasNoErrors();

        $lead = Lead::query()->sole();
        $this->assertMatchesRegularExpression('/^LD-\d{4}-00001$/', $lead->number);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame($this->rep->id, $lead->owner_id);
        $this->assertSame(15000000000, $lead->est_value_minor);
        $this->assertSame('+998901234567', $lead->contact_phone, 'Phones are normalised to +998 (shown before saving).');
        $this->assertSame(1, StatusTransition::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->whereNull('from_status')->count());
    }

    public function test_capture_needs_a_company_and_a_way_to_reach_the_contact(): void
    {
        Livewire::actingAs($this->rep)->test(Form::class)->set('contact_name', 'Someone')->call('save')->assertHasErrors('company_name');
        Livewire::actingAs($this->rep)->test(Form::class)->set('company_name', 'ACME')->call('save')->assertHasErrors('contact_phone');
        $this->assertSame(0, Lead::query()->count());
    }

    /** AC-2 */
    public function test_duplicate_customer_by_tax_id_is_shown_and_can_be_used(): void
    {
        $customer = Customer::factory()->ownedBy($this->rep)->create(['tax_id' => '123456789', 'name' => 'Samarkand Steel']);
        $other = Lead::factory()->create(['tax_id' => '123456789', 'company_name' => 'Samarkand Steel']);

        Livewire::actingAs($this->rep)->test(Form::class)
            ->set('company_name', 'Samarkand Steel LLC')
            ->set('tax_id', '123456789')
            ->set('contact_email', 'buyer@samarkand-steel.uz')
            ->assertSee(__('leads.duplicates.title'))
            ->assertSee('Samarkand Steel')
            ->assertSee($other->number)
            ->call('selectCustomer', $customer->id)
            ->assertSet('customerId', $customer->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($customer->id, Lead::query()->latest('id')->first()->customer_id);
    }

    /** AC-3 */
    public function test_qualification_gate_lists_missing_items(): void
    {
        $lead = Lead::factory()->ownedBy($this->rep)->create();

        try {
            app(LeadWorkflow::class)->qualify($lead, $this->rep);
            $this->fail('Qualification must be refused.');
        } catch (LeadActionException $exception) {
            $this->assertSame('not_qualifiable', $exception->key);
            $this->assertStringContainsString(__('leads.gate.customer'), $exception->getMessage());
            $this->assertStringContainsString(__('leads.gate.budget_confirmed'), $exception->getMessage());
        }

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);

        $qualified = $this->qualifiedLead();
        $this->assertSame(LeadStatus::Qualified, $qualified->status);
        $this->assertNotNull($qualified->qualified_at);
    }

    /** AC-4 (rev. 2026-10-19): offers go only to partners; the first offer comes from "Convert to customer". */
    public function test_first_offer_comes_from_conversion_with_the_lead_as_provenance(): void
    {
        $lead = $this->qualifiedLead();
        $first = $this->pricedItem('100.00');
        $second = $this->pricedItem('250.00');
        $inactive = $this->pricedItem('10.00', ['is_active' => false]);
        $lead->items()->create(['item_id' => $first->id, 'quantity' => '3']);
        $lead->items()->create(['item_id' => $second->id, 'quantity' => '2']);
        $lead->items()->create(['item_id' => $inactive->id, 'quantity' => '1']);

        $result = app(ConvertLeadToCustomer::class)->handle($lead, $this->rep, ['mode' => 'link', 'partner_id' => $lead->customer_id, 'create_offer' => true]);

        $offer = $result['offer'];
        $this->assertSame($lead->id, $offer->lead_id, 'Read-only provenance.');
        $this->assertSame($lead->customer_id, $offer->customer_id);
        $this->assertSame(OfferStatus::Draft, $offer->status);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $offer->lines()->pluck('item_id')->all());
        $this->assertSame('3.000', (string) $offer->lines()->where('item_id', $first->id)->value('quantity'));
        $this->assertCount(1, array_filter($result['notes'], fn (string $note): bool => str_contains($note, $inactive->name)));
        $this->assertSame(LeadStatus::Converted, $lead->fresh()->status);

        try {
            app(ConvertLeadToCustomer::class)->handle($lead->fresh(), $this->rep, ['mode' => 'link', 'partner_id' => $lead->customer_id, 'create_offer' => true]);
            $this->fail('A second conversion must be refused.');
        } catch (LeadActionException $exception) {
            $this->assertSame('already_converted', $exception->key);
        }

        $this->assertSame(1, Offer::query()->where('lead_id', $lead->id)->count());
    }

    public function test_lead_page_has_no_create_offer_and_the_offer_links_back_to_its_lead(): void
    {
        $lead = $this->qualifiedLead();
        $this->assertFalse($this->rep->can('convert', $lead), 'No lead -> offer shortcut any more.');
        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $lead])->assertDontSee('data-action="convert"', false)->assertSee('data-action="convert-customer"', false);

        $offer = app(ConvertLeadToCustomer::class)->handle($lead, $this->rep, ['mode' => 'link', 'partner_id' => $lead->customer_id, 'create_offer' => true])['offer'];
        $this->actingAs($this->rep)->get(route('offers.show', $offer))->assertOk()->assertSee($lead->number);
    }

    /** AC-5 */
    public function test_closing_needs_a_reason_and_reopen_needs_a_comment(): void
    {
        $lead = Lead::factory()->ownedBy($this->rep)->create();
        $workflow = app(LeadWorkflow::class);

        foreach ([[null, null, 'reason_required'], [LeadCloseReason::Other, 'short', 'note_required']] as [$reason, $note, $key]) {
            try {
                $workflow->close($lead, $this->rep, LeadStatus::Lost, $reason, $note);
                $this->fail('Closing must be refused.');
            } catch (LeadActionException $exception) {
                $this->assertSame($key, $exception->key);
            }
        }

        $workflow->close($lead, $this->rep, LeadStatus::Lost, LeadCloseReason::ChoseCompetitor, null);
        $lead->refresh();
        $this->assertSame(LeadStatus::Lost, $lead->status);
        $this->assertNotNull($lead->closed_at);
        $this->assertSame(__('leads.history.close', ['value' => LeadCloseReason::ChoseCompetitor->label()]), $lead->transitions()->reorder('id', 'desc')->first()->reasonLabel());

        $this->expectExceptionObject(new LeadActionException('comment_required', ['min' => 10]));
        $workflow->reopen($lead, $this->rep, 'again');
    }

    public function test_reopen_returns_to_contacted_and_clears_the_close_reason(): void
    {
        $lead = Lead::factory()->ownedBy($this->rep)->create();
        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $lead])
            ->call('openPanel', 'close')->set('closeStatus', 'disqualified')->set('closeReason', 'no_budget')->call('close')->assertHasNoErrors();
        $this->assertSame(LeadStatus::Disqualified, $lead->fresh()->status);

        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $lead])
            ->call('openPanel', 'reopen')->set('reopenComment', 'Budget approved for next quarter')->call('reopen')->assertHasNoErrors();

        $lead->refresh();
        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertNull($lead->close_reason);
        $this->assertNull($lead->closed_at);
    }

    /** AC-8 */
    public function test_transition_map_refuses_invalid_moves(): void
    {
        // Spec 04 section 18: "Convert to customer" works from any open status, never from a closed one.
        $this->assertTrue(LeadStatus::New->canTransitionTo(LeadStatus::Converted));
        $this->assertFalse(LeadStatus::Lost->canTransitionTo(LeadStatus::Converted));
        $this->assertFalse(LeadStatus::Disqualified->canTransitionTo(LeadStatus::Converted));
        $this->assertFalse(LeadStatus::Converted->canTransitionTo(LeadStatus::Contacted));
        $this->assertFalse(LeadStatus::Lost->canTransitionTo(LeadStatus::Qualified));
        $this->assertTrue(LeadStatus::Qualified->canTransitionTo(LeadStatus::Converted));

        $this->assertFalse(LeadStatus::Converted->canTransitionTo(LeadStatus::Qualified), 'A converted lead is never reopened.');
    }

    /** AC-6 */
    public function test_visibility_follows_ownership_and_department(): void
    {
        $colleague = User::factory()->sales()->create();
        $lead = Lead::factory()->ownedBy($colleague)->create();

        $this->actingAs($this->rep)->get(route('leads.show', $lead))->assertForbidden();
        $this->actingAs($this->rep)->get(route('leads.index'))->assertOk()->assertDontSee($lead->number);
        $this->actingAs(User::factory()->finance()->create())->get(route('leads.index'))->assertForbidden();
        $this->actingAs(User::factory()->manager()->create())->get(route('leads.show', $lead))->assertOk()->assertSee($lead->number);

        $head = User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create();
        $member = User::factory()->staff()->inDepartment('sales')->create();
        $deptLead = Lead::factory()->ownedBy($member)->create();
        $this->actingAs($head)->get(route('leads.show', $deptLead))->assertOk();
        $this->actingAs($head)->get(route('leads.show', $lead))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('leads.show', $lead))->assertOk();
        $this->assertFalse($admin->can('update', $lead));
        $this->actingAs($admin)->get(route('leads.edit', $lead))->assertForbidden();
    }

    public function test_head_reassigns_within_the_department(): void
    {
        $head = User::factory()->staff()->inDepartment('sales', DepartmentRole::Head)->create();
        $member = User::factory()->staff()->inDepartment('sales')->create();
        $other = User::factory()->staff()->inDepartment('sales')->create();
        $lead = Lead::factory()->ownedBy($member)->create();

        Livewire::actingAs($head)->test(Show::class, ['lead' => $lead])->call('openPanel', 'assign')->set('newOwnerId', $other->id)->call('assign')->assertHasNoErrors();
        $this->assertSame($other->id, $lead->fresh()->owner_id);

        Livewire::actingAs($member)->test(Show::class, ['lead' => Lead::factory()->ownedBy($member)->create()])->call('assign')->assertForbidden();
    }

    /** AC-7 */
    public function test_stale_filter_and_activity_logging(): void
    {
        $lead = Lead::factory()->ownedBy($this->rep)->create(['last_activity_at' => now()->subDays(15)]);
        $fresh = Lead::factory()->ownedBy($this->rep)->create();

        Livewire::actingAs($this->rep)->test(Index::class)->set('stale', true)->assertSee($lead->number)->assertDontSee($fresh->number);

        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $lead])->set('activityType', 'call')->set('activityText', 'Called the buyer, sending specs tomorrow')->call('logActivity')
            ->assertHasNoErrors()->assertSee('Called the buyer, sending specs tomorrow');

        $this->assertFalse($lead->fresh()->isStale());
        Livewire::actingAs($this->rep)->test(Index::class)->set('stale', true)->assertDontSee($lead->number);
    }

    public function test_create_customer_from_lead_links_customer_and_contact(): void
    {
        $lead = app(SaveLead::class)->handle($this->rep, [
            'source' => 'phone', 'company_name' => 'Buxoro Textile', 'tax_id' => '305123456', 'contact_name' => 'Dilnoza Rahimova',
            'contact_email' => 'dilnoza@buxoro.uz', 'currency' => 'UZS',
        ], []);

        // Spec 04 section 18: the lead page offers "Convert to customer"; the prefilled form still works.
        $this->actingAs($this->rep)->get(route('leads.show', $lead))->assertOk()->assertSee('data-action="convert-customer"', false);

        Livewire::actingAs($this->rep)->withQueryParams(['role' => 'customer', 'lead' => $lead->id])->test(PartnerForm::class)
            ->assertSet('form.name', 'Buxoro Textile')
            ->assertSet('form.contact_first_name', 'Dilnoza')
            ->set('form.legal_name', 'Buxoro Textile MChJ')
            ->call('save')
            ->assertRedirect(route('leads.show', $lead));

        $lead->refresh();
        $this->assertNotNull($lead->customer_id);
        $this->assertSame('Buxoro Textile', $lead->customer->name);
        $this->assertSame('dilnoza@buxoro.uz', $lead->contact?->email);
    }

    public function test_offers_lead_id_has_a_foreign_key(): void
    {
        $this->assertContains('leads', array_column(Schema::getForeignKeys('offers'), 'foreign_table'));
        $this->assertContains('leads', array_column(Schema::getForeignKeys('orders'), 'foreign_table'));
    }
}
