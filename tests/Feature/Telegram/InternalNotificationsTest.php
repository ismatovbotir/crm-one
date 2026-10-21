<?php

namespace Tests\Feature\Telegram;

use App\Actions\Leads\TransitionLead;
use App\Actions\Service\ManageServiceTicket;
use App\Actions\Telegram\ManageInternalTelegram;
use App\Enums\AssetSource;
use App\Enums\InternalEvent;
use App\Enums\LeadStatus;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatPurpose;
use App\Enums\TelegramChatType;
use App\Jobs\PostInternalTelegramMessage;
use App\Livewire\Settings\TelegramNotifications;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\TelegramDestination;
use App\Models\TelegramLink;
use App\Models\TelegramPost;
use App\Models\User;
use App\Notifications\TelegramBotRemoved;
use App\Services\Equipment\AssetRegister;
use App\Services\Telegram\Internal\InternalNotifications;
use App\Services\Telegram\Internal\InternalScheduler;
use App\Services\Telegram\Internal\MessageRenderer;
use App\Services\Telegram\TelegramChats;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 03 section 23: company groups — routing matrix, content levels (no amounts, contacts or
 * serials unless allowed), internal chats never create leads, command authorisation, dedupe,
 * supergroup migration, escaping and splitting, settings permissions, scheduled reports, alerts.
 */
class InternalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private User $admin;

    /** @var \Closure(HttpRequest): mixed */
    private \Closure $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responder = fn (): mixed => Http::response(['ok' => true, 'result' => ['message_id' => 1]]);
        Http::preventStrayRequests();
        Http::fake(fn (HttpRequest $request) => ($this->responder)($request));
        Setting::putMany([
            'telegram.enabled' => '1',
            'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)),
            'telegram.bot_username' => 'crm_test_bot',
            'telegram.webhook_secret' => self::SECRET,
        ]);
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function destination(int $chatId, array $attributes = []): TelegramDestination
    {
        return app(ManageInternalTelegram::class)->saveDestination(['name' => 'Group '.$chatId, 'chat_id' => $chatId, ...$attributes], $this->admin);
    }

    /**
     * @return list<string>
     */
    private function textsTo(int $chatId): array
    {
        return collect(Http::recorded())->map(fn (array $pair): HttpRequest => $pair[0])
            ->filter(fn (HttpRequest $request): bool => str_ends_with($request->url(), '/sendMessage') && (int) ($request->data()['chat_id'] ?? 0) === $chatId)
            ->map(fn (HttpRequest $request): string => (string) $request->data()['text'])->values()->all();
    }

    private function webhook(array $message): void
    {
        static $updateId = 5000;
        $this->postJson(route('telegram.webhook'), ['update_id' => ++$updateId, 'message' => $message], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    public function test_routing_matrix_and_content_levels_keep_amounts_and_contacts_out(): void
    {
        $minimal = $this->destination(-1001);
        $standard = $this->destination(-1002, ['include_amounts' => true, 'locale' => 'en']);
        app(ManageInternalTelegram::class)->saveRouting($standard, [InternalEvent::LeadCreated->value => 'standard'], $this->admin);
        $off = $this->destination(-1003);
        app(ManageInternalTelegram::class)->saveRouting($off, [InternalEvent::OrderCreated->value => 'minimal'], $this->admin);

        $lead = Lead::factory()->create([
            'company_name' => 'Samarkand <Steel>',
            'contact_phone' => '+998901234567',
            'contact_email' => 'boss@steel.uz',
            'need' => 'Call me on +998 90 765 43 21 or boss@steel.uz about 10 printers',
            'est_value_minor' => 1234500,
        ]);
        TransitionLead::recordCreation($lead, null);

        $min = $this->textsTo(-1001);
        $this->assertCount(1, $min);
        $this->assertStringContainsString($lead->number, $min[0]);
        $this->assertStringContainsString(route('leads.show', $lead), $min[0]);
        $this->assertStringNotContainsString('Samarkand', $min[0], 'Minimal: no customer name.');
        $this->assertStringNotContainsString('12', $min[0] === '' ? '' : str_replace([$lead->number, route('leads.show', $lead)], '', $min[0]), 'No amounts.');

        $std = $this->textsTo(-1002)[0];
        $this->assertStringContainsString('Samarkand &lt;Steel&gt;', $std, 'Escaped.');
        $this->assertStringContainsString('12', $std, 'Amounts only where enabled.');
        $this->assertStringContainsString('10 printers', $std);
        $this->assertStringNotContainsString('765', $std, 'Phones are scrubbed from excerpts.');
        $this->assertStringNotContainsString('boss@steel.uz', $std);
        $this->assertStringNotContainsString('901234567', $std);

        $this->assertSame([], $this->textsTo(-1003), 'Event not routed to this group.');
        $post = TelegramPost::query()->where('telegram_destination_id', $minimal->id)->sole();
        $this->assertSame('sent', $post->status);
        $this->assertSame(64, strlen((string) $post->message_hash));
    }

    public function test_service_tickets_never_carry_serial_numbers(): void
    {
        $this->destination(-1004, ['include_amounts' => true]);
        app(ManageInternalTelegram::class)->saveRouting(TelegramDestination::query()->sole(), [InternalEvent::ServiceTicketOpened->value => 'standard'], $this->admin);
        $customer = Customer::factory()->create(['name' => 'Client Co']);
        $engineer = User::factory()->manager()->create();
        $asset = app(AssetRegister::class)->register(Partner::query()->findOrFail($customer->id), ['serial' => 'SN-SECRET-77', 'model_name' => 'Printer X9'], $engineer, AssetSource::StaffRegistered);
        app(ManageServiceTicket::class)->open($asset, ['problem' => 'Paper jam'], $engineer, 'staff');

        $text = $this->textsTo(-1004)[0];
        $this->assertStringContainsString('Printer X9', $text);
        $this->assertStringNotContainsString('SN-SECRET-77', $text);
    }

    public function test_internal_group_never_creates_leads_and_commands_need_a_linked_authorised_user(): void
    {
        $this->destination(-1005);
        $chat = TelegramChat::query()->where('chat_id', -1005)->sole();
        $this->assertTrue($chat->isInternal());
        $base = ['chat' => ['id' => -1005, 'type' => 'supergroup', 'title' => 'Our team']];

        $this->webhook([...$base, 'message_id' => 1, 'from' => ['id' => 901, 'first_name' => 'Ali'], 'text' => 'Hello team, need 5 printers']);
        $this->assertSame(0, Lead::query()->count(), 'Company groups never create leads.');

        $this->webhook([...$base, 'message_id' => 2, 'from' => ['id' => 902, 'first_name' => 'Guest'], 'text' => '/status']);
        $refusals = $this->textsTo(-1005);
        $this->assertStringContainsString(e(__('telegram.internal.commands.refused', [], 'uz')), end($refusals));

        $sales = User::factory()->sales()->create();
        TelegramLink::query()->create(['user_id' => $sales->id, 'chat_id' => 903, 'telegram_user_id' => 903, 'linked_at' => now(), 'verified_at' => now()]);
        Lead::factory()->create(['status' => LeadStatus::New]);
        $this->webhook([...$base, 'message_id' => 3, 'from' => ['id' => 903, 'first_name' => 'Rep'], 'text' => '/status@crm_test_bot']);
        $answer = collect($this->textsTo(-1005))->last();
        $this->assertStringContainsString(__('telegram.internal.commands.status_title', [], $sales->locale?->value ?? 'uz'), $answer);

        $staff = User::factory()->create(['role' => 'staff']);
        TelegramLink::query()->create(['user_id' => $staff->id, 'chat_id' => 904, 'telegram_user_id' => 904, 'linked_at' => now(), 'verified_at' => now()]);
        $this->webhook([...$base, 'message_id' => 4, 'from' => ['id' => 904, 'first_name' => 'Clerk'], 'text' => '/orders today']);
        $this->assertStringContainsString(e(__('telegram.internal.commands.refused', [], 'uz')), collect($this->textsTo(-1005))->last());
        $this->assertSame(1, Lead::query()->count(), 'Commands never change data.');
    }

    public function test_events_are_deduplicated_per_destination(): void
    {
        $this->destination(-1006);
        $lead = Lead::factory()->create();
        $notifications = app(InternalNotifications::class);

        $this->assertSame(1, $notifications->publish(InternalEvent::LeadCreated, $lead, [], 'lead.created:x'));
        $this->assertSame(0, $notifications->publish(InternalEvent::LeadCreated, $lead, [], 'lead.created:x'));
        $this->assertCount(1, $this->textsTo(-1006));
        $this->assertSame(1, TelegramPost::query()->count());
    }

    public function test_supergroup_migration_moves_the_destination(): void
    {
        $destination = $this->destination(-1007);
        $this->webhook(['chat' => ['id' => -1007, 'type' => 'group', 'title' => 'Team'], 'message_id' => 9, 'from' => ['id' => 1, 'first_name' => 'X'], 'migrate_to_chat_id' => -1001007]);
        $this->assertSame(-1001007, $destination->fresh()->chat_id);

        // Telegram reports a migration at send time.
        $other = $this->destination(-1008);
        $this->responder = fn (HttpRequest $request): mixed => (int) ($request->data()['chat_id'] ?? 0) === -1008
            ? Http::response(['ok' => false, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1001008]], 400)
            : Http::response(['ok' => true, 'result' => ['message_id' => 2]]);
        $post = TelegramPost::query()->create(['telegram_destination_id' => $other->id, 'event' => 'test', 'event_key' => 'm1', 'chat_id' => -1008, 'status' => 'queued']);
        (new PostInternalTelegramMessage($post->id, 'hello'))->handle(app(TelegramClient::class));
        $this->assertSame(-1001008, $other->fresh()->chat_id);
        (new PostInternalTelegramMessage($post->id, 'hello'))->handle(app(TelegramClient::class));
        $this->assertSame('sent', $post->fresh()->status);
        $this->assertSame(-1001008, $post->fresh()->chat_id);
    }

    public function test_escaping_and_splitting(): void
    {
        $this->assertSame('«&lt;script&gt;alert(1)&lt;/script&gt; [ ]»', str_replace('[…]', '[ ]', (string) MessageRenderer::excerpt('<script>alert(1)</script> +998 90 111 22 33')));
        $html = implode("\n", array_fill(0, 300, '<b>Line</b> '.str_repeat('x', 30)));
        $parts = MessageRenderer::split($html);
        $this->assertGreaterThan(1, count($parts));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(MessageRenderer::MAX_LENGTH, mb_strlen($part));
            $this->assertSame(substr_count($part, '<b>'), substr_count($part, '</b>'), 'Tags stay closed in every part.');
        }

        $this->assertSame(substr_count($html, "\n") + 1, array_sum(array_map(fn (string $p): int => substr_count($p, "\n") + 1, $parts)));
    }

    public function test_settings_are_admin_only_and_marking_a_chat_internal_needs_confirmation_for_its_lead(): void
    {
        Livewire::actingAs(User::factory()->sales()->create())->test(TelegramNotifications::class)->assertForbidden();
        $group = TelegramChat::query()->create(['chat_id' => -1009, 'type' => TelegramChatType::Supergroup, 'title' => 'Staff room', 'bot_status' => TelegramBotStatus::Admin, 'first_seen_at' => now()]);
        $lead = Lead::factory()->create(['telegram_chat_id' => -1009]);
        $group->forceFill(['lead_id' => $lead->id])->save();

        $component = Livewire::actingAs($this->admin)->test(TelegramNotifications::class)
            ->call('newDestination', -1009)
            ->assertSet('form.chat_id', '-1009')
            ->call('saveDestination')
            ->assertSet('showForm', true)
            ->assertSee($lead->number);
        $this->assertSame(0, TelegramDestination::query()->count());
        $this->assertSame(LeadStatus::New, $lead->fresh()->status, 'Nothing changes without confirmation.');

        $component->set('confirmLeadClose', true)->call('saveDestination')->assertSet('showForm', false);
        $this->assertSame(LeadStatus::Disqualified, $lead->fresh()->status, 'Closed, never deleted.');
        $this->assertSame(TelegramChatPurpose::InternalNotifications, $group->fresh()->purpose);
        $destination = TelegramDestination::query()->sole();
        $this->assertNotNull($destination->levelFor(InternalEvent::OrderConfirmed), 'Default: all events, minimal.');
        $this->assertNull($destination->levelFor(InternalEvent::ReportWeekly));
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'telegram_destination', 'subject_id' => $destination->id, 'event' => 'created']);

        $component->set('matrix.'.$destination->id.'.'.InternalEvent::OrderConfirmed->name, '')->call('saveRouting');
        $this->assertNull($destination->fresh()->levelFor(InternalEvent::OrderConfirmed));
        $component->call('sendTest', $destination->id);
        $this->assertCount(1, $this->textsTo(-1009));
    }

    public function test_daily_report_is_posted_once_after_its_time_without_amounts(): void
    {
        $this->destination(-1010);
        Lead::factory()->count(2)->create();
        $scheduler = app(InternalScheduler::class);

        $this->assertSame(0, $scheduler->tick(CarbonImmutable::parse('2026-10-19 17:55', 'Asia/Tashkent'))['daily']);
        $this->assertSame(1, $scheduler->tick(CarbonImmutable::parse('2026-10-19 18:05', 'Asia/Tashkent'))['daily']);
        $this->assertSame(0, $scheduler->tick(CarbonImmutable::parse('2026-10-19 18:10', 'Asia/Tashkent'))['daily']);

        $text = $this->textsTo(-1010)[0];
        $this->assertStringContainsString(e(__('telegram.internal.report.rows.leads_created', [], 'uz')), $text);
        $this->assertStringNotContainsString(e(__('telegram.internal.report.rows.outstanding_amount', [], 'uz')), $text, 'Amounts are off by default.');
    }

    public function test_admins_are_alerted_when_the_bot_is_removed_or_cannot_post(): void
    {
        Notification::fake();
        $destination = $this->destination(-1011);
        app(TelegramChats::class)->botStatusChanged(['chat' => ['id' => -1011, 'type' => 'supergroup', 'title' => 'Team'], 'from' => ['id' => 5, 'first_name' => 'Owner'], 'new_chat_member' => ['status' => 'kicked']]);
        Notification::assertSentTo($this->admin, TelegramBotRemoved::class);

        $this->responder = fn (): mixed => Http::response(['ok' => false, 'description' => 'Forbidden: bot was kicked'], 403);
        app(InternalNotifications::class)->test($destination);
        $this->assertSame('bot_blocked', $destination->fresh()->last_error);
        $this->assertSame('failed', TelegramPost::query()->latest('id')->first()->status);
    }
}
