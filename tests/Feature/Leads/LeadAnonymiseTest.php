<?php

namespace Tests\Feature\Leads;

use App\Actions\Leads\LeadWorkflow;
use App\Enums\LeadStatus;
use App\Livewire\Leads\Show;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\Setting;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 04 section 19: `leads:anonymise` removes personal data from lost and disqualified leads
 * closed before the retention cutoff and nothing else.
 */
class LeadAnonymiseTest extends TestCase
{
    use RefreshDatabase;

    private function closedLead(LeadStatus $status, int $closedDaysAgo, array $attributes = []): Lead
    {
        return Lead::factory()->create([
            'status' => $status,
            'closed_at' => now()->subDays($closedDaysAgo),
            'contact_email' => 'person@example.test',
            'tax_id' => '123456789',
            'telegram_user_id' => 555,
            'telegram_username' => 'person',
            'close_note' => 'called the owner on his private number',
            ...$attributes,
        ]);
    }

    public function test_old_closed_leads_lose_personal_data_and_keep_business_data(): void
    {
        $old = $this->closedLead(LeadStatus::Lost, 800, ['telegram_chat_id' => 555]);
        $disqualified = $this->closedLead(LeadStatus::Disqualified, 800);
        $recent = $this->closedLead(LeadStatus::Lost, 100);
        $open = Lead::factory()->create(['contact_email' => 'open@example.test', 'created_at' => now()->subDays(900)]);
        $converted = Lead::factory()->create(['status' => LeadStatus::Converted, 'closed_at' => now()->subDays(900), 'contact_email' => 'kept@example.test']);
        $company = $old->company_name;
        $old->logActivity('lead_activity', ['type' => 'call', 'text' => 'Ali, +998901234567']);
        LeadFollowUp::query()->create(['lead_id' => $old->id, 'due_at' => now()->subYear(), 'note' => 'call Ali']);
        StatusTransition::query()->create(['subject_type' => $old->getMorphClass(), 'subject_id' => $old->id, 'from_status' => 'new', 'to_status' => 'lost', 'reason' => 'close:no_budget — Ali has no money', 'created_at' => now()->subDays(800)]);

        $this->artisan('leads:anonymise', ['--dry-run' => true])->expectsOutputToContain('Would anonymise 2')->assertSuccessful();
        $this->assertNotNull($old->fresh()->contact_email);

        $this->artisan('leads:anonymise')->expectsOutputToContain('Anonymised 2')->assertSuccessful();

        foreach ([$old->fresh(), $disqualified->fresh()] as $lead) {
            $this->assertNotNull($lead->anonymised_at);
            $this->assertNull($lead->contact_name);
            $this->assertNull($lead->contact_email);
            $this->assertNull($lead->contact_phone);
            $this->assertNull($lead->tax_id);
            $this->assertNull($lead->telegram_user_id);
            $this->assertNull($lead->close_note);
        }

        $old = $old->fresh();
        $this->assertNull($old->telegram_chat_id, 'A private chat id is the person\'s Telegram id.');
        $this->assertSame($company, $old->company_name);
        $this->assertSame(LeadStatus::Lost, $old->status);
        $this->assertNull(LeadFollowUp::query()->where('lead_id', $old->id)->value('note'));
        $this->assertSame('close:no_budget', StatusTransition::query()->where('subject_id', $old->id)->value('reason'));

        $log = ActivityLog::query()->where('subject_type', $old->getMorphClass())->where('subject_id', $old->id)->get();
        $this->assertSame('', $log->firstWhere('event', 'lead_activity')->properties['text']);
        $this->assertStringNotContainsString('person@example.test', json_encode($log->pluck('properties')));
        $this->assertTrue($log->contains('event', 'anonymised'));

        $this->assertNull($recent->fresh()->anonymised_at);
        $this->assertSame('open@example.test', $open->fresh()->contact_email);
        $this->assertSame('kept@example.test', $converted->fresh()->contact_email);

        $this->artisan('leads:anonymise')->expectsOutputToContain('Anonymised 0')->assertSuccessful();
        $this->assertSame(1, $log->where('event', 'anonymised')->count());
    }

    public function test_retention_months_come_from_settings_with_a_floor_of_six(): void
    {
        $lead = $this->closedLead(LeadStatus::Lost, 250);
        $this->artisan('leads:anonymise')->assertSuccessful();
        $this->assertNull($lead->fresh()->anonymised_at, '24 months by default.');

        $recent = $this->closedLead(LeadStatus::Lost, 100);
        Setting::putMany(['operations.retention.closed_leads_months' => '1']);
        $this->artisan('leads:anonymise')->assertSuccessful();
        $this->assertNotNull($lead->fresh()->anonymised_at, 'A value below 6 months is raised to 6.');
        $this->assertNull($recent->fresh()->anonymised_at);
    }

    public function test_an_anonymised_lead_cannot_be_reopened(): void
    {
        $lead = $this->closedLead(LeadStatus::Lost, 800);
        $this->artisan('leads:anonymise')->assertSuccessful();
        $lead = $lead->fresh();
        $owner = User::query()->findOrFail($lead->owner_id);

        $this->assertFalse($owner->can('reopen', $lead));
        $this->expectException(LeadActionException::class);
        app(LeadWorkflow::class)->reopen($lead, $owner, 'trying to reopen after removal');
    }

    public function test_the_lead_page_shows_the_removed_badge_without_a_reopen_button(): void
    {
        $lead = $this->closedLead(LeadStatus::Lost, 800);
        $this->artisan('leads:anonymise')->assertSuccessful();
        $owner = User::query()->findOrFail($lead->owner_id);

        Livewire::actingAs($owner)->test(Show::class, ['lead' => $lead->fresh()])
            ->assertSeeHtml('data-anonymised')
            ->assertDontSeeHtml('data-action="reopen"');
    }
}
