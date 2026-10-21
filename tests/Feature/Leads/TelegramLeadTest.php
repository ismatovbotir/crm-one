<?php

namespace Tests\Feature\Leads;

use App\Actions\Customers\ManagePortalAccess;
use App\Actions\Leads\LeadWorkflow;
use App\Enums\LeadCloseReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\TelegramLink;
use App\Models\User;
use App\Notifications\NewTelegramLead;
use App\Services\Telegram\TelegramLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Spec 04 section 15.2: every message to the bot is lead work: unknown senders → `telegram` lead,
 * linked portal users → `portal_request` on their customer, staff → no lead; follow-ups append;
 * a closed lead makes the next message open a new one; commands never create leads; attachments
 * are a note only; per-chat rate limit; acknowledgement in the sender's language; owner notified.
 */
class TelegramLeadTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::putMany([
            'telegram.enabled' => '1',
            'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)),
            'telegram.bot_username' => 'crm_test_bot',
            'telegram.webhook_secret' => self::SECRET,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function send(int $chatId, ?string $text, array $extra = [], array $from = []): TestResponse
    {
        $message = ['message_id' => ++$this->updateId, 'chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId, 'first_name' => 'Dilshod', 'last_name' => 'Rakhimov', 'username' => 'dilshod_r', 'language_code' => 'ru', ...$from], ...$extra];

        if ($text !== null) {
            $message['text'] = $text;
        }

        return $this->postJson(route('telegram.webhook'), ['update_id' => $this->updateId, 'message' => $message], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_unknown_sender_creates_one_lead_and_follow_ups_append(): void
    {
        Notification::fake();
        $sales = User::factory()->sales()->create();

        $this->send(5001, 'Здравствуйте, нужны 10 ноутбуков для офиса');
        $lead = Lead::query()->sole();
        $this->assertSame(LeadSource::Telegram, $lead->source);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertSame(5001, (int) $lead->telegram_chat_id);
        $this->assertSame('dilshod_r', $lead->telegram_username);
        $this->assertSame('ru', $lead->locale);
        $this->assertSame('Dilshod Rakhimov', $lead->contact_name);
        $this->assertStringContainsString('10 ноутбуков', $lead->need);
        $this->assertSame($sales->id, $lead->owner_id, 'Round robin picks the sales user.');
        Notification::assertSentTo($sales, NewTelegramLead::class);
        Http::assertSent(fn (HttpRequest $request) => (int) $request['chat_id'] === 5001 && str_contains((string) $request['text'], __('telegram.lead.ack', [], 'ru')));

        $this->send(5001, 'Лучше 12 штук');
        $this->assertSame(1, Lead::query()->count());
        $this->assertTrue(ActivityLog::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->where('event', 'lead_activity')->get()->contains(fn ($row) => $row->properties['text'] === 'Лучше 12 штук'));

        app(LeadWorkflow::class)->close($lead, $sales, LeadStatus::Lost, LeadCloseReason::NoResponse, null);
        $this->send(5001, 'Снова нужен расчёт');
        $this->assertSame(2, Lead::query()->count(), 'A closed lead makes the next message open a new one.');
    }

    public function test_round_robin_alternates_and_can_be_switched_off(): void
    {
        $first = User::factory()->sales()->create();
        $second = User::factory()->sales()->create();

        $this->send(6001, 'one');
        $this->send(6002, 'two');
        $this->assertSame([$first->id, $second->id], Lead::query()->orderBy('id')->pluck('owner_id')->all());

        Setting::putMany(['leads.round_robin' => '0']);
        $this->send(6003, 'three');
        $unassigned = Lead::query()->latest('id')->first();
        $this->assertNull($unassigned->owner_id);

        $manager = User::factory()->manager()->create();
        $this->assertTrue($manager->can('view', $unassigned));
        $this->assertTrue($manager->can('assign', $unassigned));
        $this->assertFalse($first->can('view', $unassigned), 'Sales see only their own leads.');
    }

    public function test_portal_user_message_becomes_a_portal_request_on_their_customer(): void
    {
        Notification::fake();
        $owner = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($owner)->create();
        $access = app(ManagePortalAccess::class);
        $access->configure($customer, true, null);
        $member = $access->grant($customer->fresh(), 'buyer@client.uz', 'Buyer', ['buyer'], null, null, $owner);
        TelegramLink::query()->create(['user_id' => $member->user_id, 'chat_id' => 7001, 'telegram_user_id' => 7001, 'linked_at' => now(), 'verified_at' => now()]);

        $this->send(7001, 'Please quote 3 more routers');

        $lead = Lead::query()->sole();
        $this->assertSame(LeadSource::PortalRequest, $lead->source);
        $this->assertSame($customer->id, $lead->customer_id);
        $this->assertSame($owner->id, $lead->owner_id);
        Notification::assertSentTo($owner, NewTelegramLead::class);
    }

    public function test_staff_messages_and_commands_never_create_leads(): void
    {
        $staff = User::factory()->sales()->create();
        TelegramLink::query()->create(['user_id' => $staff->id, 'chat_id' => 8001, 'telegram_user_id' => 8001, 'linked_at' => now(), 'verified_at' => now()]);

        $this->send(8001, 'hello from staff');
        $this->send(9001, '/help');
        $this->send(9001, '/start');
        $token = app(TelegramLinks::class)->createToken(User::factory()->sales()->create())['token'];
        $this->send(9002, '/start '.$token);

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_attachments_are_noted_not_downloaded_and_bots_are_ignored(): void
    {
        $this->send(9100, null, ['photo' => [['file_id' => 'a', 'file_size' => 2048], ['file_id' => 'b', 'file_size' => 204800]], 'caption' => 'Our spec']);
        $lead = Lead::query()->sole();
        $this->assertStringContainsString('Our spec', $lead->need);
        $this->assertStringContainsString(__('telegram.lead.kinds.photo', [], 'uz'), $lead->need);
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'getFile'));

        $this->send(9200, 'I am a bot', [], ['is_bot' => true]);
        $this->assertSame(1, Lead::query()->count());
    }

    public function test_per_chat_rate_limit_and_idempotent_updates(): void
    {
        config(['leads.telegram_rate_per_hour' => 3]);

        foreach (range(1, 5) as $n) {
            $this->send(9300, 'message '.$n);
        }

        $lead = Lead::query()->sole();
        $this->assertSame(2, ActivityLog::query()->where('subject_type', 'lead')->where('subject_id', $lead->id)->where('event', 'lead_activity')->count(), 'Only 3 messages per hour are processed (1 lead + 2 notes).');

        // Same update_id twice is processed once.
        $payload = ['update_id' => 99999, 'message' => ['message_id' => 1, 'chat' => ['id' => 9400, 'type' => 'private'], 'from' => ['id' => 9400, 'first_name' => 'X'], 'text' => 'hi']];
        $this->postJson(route('telegram.webhook'), $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->postJson(route('telegram.webhook'), $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->assertSame(1, Lead::query()->where('telegram_chat_id', 9400)->count());
        $this->postJson(route('telegram.webhook'), ['update_id' => 100000] + $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }
}
