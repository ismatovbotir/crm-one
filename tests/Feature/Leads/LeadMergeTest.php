<?php

namespace Tests\Feature\Leads;

use App\Actions\Leads\MergeLeads;
use App\Enums\LeadCloseReason;
use App\Enums\LeadStatus;
use App\Livewire\Leads\Show;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Lead;
use App\Models\LeadFollowUp;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 04 section 20: a duplicate open lead is merged into another open lead; nothing is deleted.
 */
class LeadMergeTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
    }

    public function test_merge_moves_everything_to_the_target_and_closes_the_source_as_duplicate(): void
    {
        $item = Item::factory()->create();
        $other = Item::factory()->create();
        $target = Lead::factory()->ownedBy($this->rep)->create(['contact_email' => null, 'tax_id' => null, 'need' => 'Need A', 'est_value_minor' => null]);
        $source = Lead::factory()->ownedBy($this->rep)->create(['contact_email' => 'dup@example.test', 'tax_id' => '123456789', 'need' => 'Need B', 'est_value_minor' => 5_000_000, 'currency' => 'UZS', 'telegram_chat_id' => -1001]);
        $target->items()->create(['item_id' => $item->id, 'quantity' => '2.000']);
        $source->items()->createMany([['item_id' => $item->id, 'quantity' => '3.500'], ['item_id' => $other->id, 'quantity' => '1.000']]);
        $followUp = LeadFollowUp::query()->create(['lead_id' => $source->id, 'due_at' => now()->addDay(), 'note' => 'call']);
        DB::table('telegram_chats')->insert(['chat_id' => -1001, 'type' => 'group', 'first_seen_at' => now(), 'lead_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        $result = app(MergeLeads::class)->handle($source, $target, $this->rep);

        $this->assertTrue($result->is($target));
        $target = $target->fresh();
        $this->assertSame('dup@example.test', $target->contact_email);
        $this->assertSame('123456789', $target->tax_id);
        $this->assertSame(5_000_000, $target->est_value_minor);
        $this->assertSame(-1001, $target->telegram_chat_id);
        $this->assertStringContainsString('Need A', $target->need);
        $this->assertStringContainsString($source->number.': Need B', $target->need);
        $this->assertEqualsCanonicalizing(['5.500', '1.000'], $target->items()->pluck('quantity')->all(), 'Same item adds up, others move.');
        $this->assertSame($target->id, $followUp->fresh()->lead_id);
        $this->assertSame($target->id, DB::table('telegram_chats')->where('chat_id', -1001)->value('lead_id'));
        $this->assertGreaterThan(0, $target->score);

        $source = $source->fresh();
        $this->assertSame(LeadStatus::Lost, $source->status);
        $this->assertSame(LeadCloseReason::Duplicate, $source->close_reason);
        $this->assertSame($target->id, $source->merged_into_id);
        $this->assertSame(0, $source->items()->count());
        $this->assertSame('merged_into:'.$target->number, StatusTransition::query()->where('subject_id', $source->id)->latest('id')->value('reason'));
        $this->assertTrue(ActivityLog::query()->where('subject_id', $source->id)->where('event', 'merged_into')->exists());
        $this->assertTrue(ActivityLog::query()->where('subject_id', $target->id)->where('event', 'merged_from')->exists());
        $this->assertFalse($this->rep->can('reopen', $source), 'A merged lead is not reopened.');
    }

    public function test_leads_with_different_customers_are_refused_and_nothing_changes(): void
    {
        $source = Lead::factory()->ownedBy($this->rep)->create(['customer_id' => Customer::factory()->create()->id]);
        $target = Lead::factory()->ownedBy($this->rep)->create(['customer_id' => Customer::factory()->create()->id]);

        try {
            app(MergeLeads::class)->handle($source, $target, $this->rep);
            $this->fail('Expected a LeadActionException.');
        } catch (LeadActionException $exception) {
            $this->assertSame('merge_customer_conflict', $exception->key);
        }

        $this->assertSame(LeadStatus::New, $source->fresh()->status);
        $this->assertNull($source->fresh()->merged_into_id);
    }

    public function test_the_customer_of_the_source_moves_to_a_target_without_one(): void
    {
        $customer = Customer::factory()->create();
        $source = Lead::factory()->ownedBy($this->rep)->create(['customer_id' => $customer->id]);
        $target = Lead::factory()->ownedBy($this->rep)->create();

        app(MergeLeads::class)->handle($source, $target, $this->rep);

        $this->assertSame($customer->id, $target->fresh()->customer_id);
    }

    public function test_only_open_leads_the_user_manages_can_be_merged(): void
    {
        $mine = Lead::factory()->ownedBy($this->rep)->create();
        $foreign = Lead::factory()->create();
        $closed = Lead::factory()->ownedBy($this->rep)->create(['status' => LeadStatus::Lost, 'closed_at' => now()]);

        foreach ([[$mine, $foreign], [$mine, $closed], [$closed, $mine]] as [$source, $target]) {
            try {
                app(MergeLeads::class)->handle($source, $target, $this->rep);
                $this->fail('Expected a refusal.');
            } catch (LeadActionException $exception) {
                $this->assertSame('merge_forbidden', $exception->key);
            }
        }

        $this->expectException(LeadActionException::class);
        app(MergeLeads::class)->handle($mine, $mine, $this->rep);
    }

    public function test_merge_from_the_lead_page_suggests_duplicates_and_opens_the_target(): void
    {
        $source = Lead::factory()->ownedBy($this->rep)->create(['contact_phone' => '+998901112233']);
        $target = Lead::factory()->ownedBy($this->rep)->create(['contact_phone' => '+998901112233']);

        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $source])
            ->call('openPanel', 'merge')
            ->assertSeeHtml('data-merge-candidate="'.$target->number.'"')
            ->set('mergeNumber', 'LD-0000-00000')->call('merge')->assertHasErrors(['mergeNumber'])
            ->set('mergeNumber', strtolower($target->number))->call('merge')
            ->assertHasNoErrors()->assertRedirect(route('leads.show', $target));

        $this->assertSame($target->id, $source->fresh()->merged_into_id);
    }

    public function test_the_merge_button_is_hidden_for_closed_and_foreign_leads(): void
    {
        $closed = Lead::factory()->ownedBy($this->rep)->create(['status' => LeadStatus::Lost, 'closed_at' => now()]);
        $foreign = Lead::factory()->create();

        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $closed])->assertDontSeeHtml('data-action="merge"');
        $this->assertFalse($this->rep->can('merge', $foreign));
    }
}
