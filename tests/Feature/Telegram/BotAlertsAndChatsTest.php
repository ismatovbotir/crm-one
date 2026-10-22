<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramChatType;
use App\Livewire\Settings\Telegram as TelegramSettings;
use App\Livewire\Telegram\ChatsIndex;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\TelegramContact;
use App\Models\User;
use App\Services\Telegram\TelegramAdminAlerts;
use App\Services\Telegram\TelegramChats;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bot errors reach the administrator's Telegram id (throttled, never recursive), and the chat
 * registry records chat id, sender id, bot senders and the bot's own identity.
 */
class BotAlertsAndChatsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private const ADMIN_ID = 555000111;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        Setting::putMany([
            'telegram.enabled' => '1',
            'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)),
            'telegram.webhook_secret' => self::SECRET,
        ]);
    }

    /**
     * @return list<array{method: string, data: array<string, mixed>}>
     */
    private function calls(): array
    {
        return collect(Http::recorded())->map(fn (array $pair): array => [
            'method' => basename((string) parse_url($pair[0]->url(), PHP_URL_PATH)),
            'data' => $pair[0]->data(),
        ])->values()->all();
    }

    private function adminAlertCount(): int
    {
        return count(array_filter($this->calls(), fn (array $call): bool => $call['method'] === 'sendMessage' && (int) $call['data']['chat_id'] === self::ADMIN_ID));
    }

    public function test_telegram_errors_are_sent_to_the_admin_id_once_per_window(): void
    {
        Setting::putMany([TelegramAdminAlerts::SETTING_KEY => (string) self::ADMIN_ID]);
        Http::fake(function (HttpRequest $request) {
            return str_contains($request->url(), '/getMe') ? Http::response(['ok' => false, 'description' => 'Unauthorized'], 401) : Http::response(['ok' => true, 'result' => true]);
        });

        foreach (range(1, 3) as $ignored) {
            try {
                app(TelegramClient::class)->getMe();
            } catch (\Throwable) {
            }
        }

        $this->assertSame(1, $this->adminAlertCount(), 'Same error is throttled.');
        $text = (string) collect($this->calls())->firstWhere('method', 'sendMessage')['data']['text'];
        $this->assertStringContainsString('getMe', $text);
        $this->assertStringNotContainsString('123456:', $text, 'The token never appears in an alert.');
    }

    public function test_no_alert_without_an_admin_id_and_a_failing_alert_does_not_loop(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

        try {
            app(TelegramClient::class)->getMe();
        } catch (\Throwable) {
        }
        $this->assertCount(1, $this->calls(), 'Only the original call: nobody to alert.');

        Setting::putMany([TelegramAdminAlerts::SETTING_KEY => (string) self::ADMIN_ID]);
        Cache::flush();

        try {
            app(TelegramClient::class)->getMe();
        } catch (\Throwable) {
        }

        // getMe + one alert attempt (which fails with 401 as well) and nothing more.
        $this->assertCount(3, $this->calls());
    }

    public function test_users_blocking_the_bot_and_rate_limits_are_not_reported(): void
    {
        Setting::putMany([TelegramAdminAlerts::SETTING_KEY => (string) self::ADMIN_ID]);
        Http::fake(fn (HttpRequest $request) => match ((int) ($request->data()['chat_id'] ?? 0)) {
            777 => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403),
            888 => Http::response(['ok' => false, 'parameters' => ['retry_after' => 3]], 429),
            default => Http::response(['ok' => true, 'result' => true]),
        });

        foreach ([777, 888] as $chatId) {
            try {
                app(TelegramClient::class)->sendMessage($chatId, 'hi');
            } catch (\Throwable) {
            }
        }

        $this->assertSame(0, $this->adminAlertCount());
    }

    public function test_group_errors_such_as_missing_rights_are_reported(): void
    {
        Setting::putMany([TelegramAdminAlerts::SETTING_KEY => (string) self::ADMIN_ID]);
        Http::fake(fn (HttpRequest $request) => (int) ($request->data()['chat_id'] ?? 0) === -100200
            ? Http::response(['ok' => false, 'description' => 'Bad Request: not enough rights to send text messages to the chat'], 400)
            : Http::response(['ok' => true, 'result' => true]));

        try {
            app(TelegramClient::class)->sendMessage(-100200, 'hi');
        } catch (\Throwable) {
        }

        $this->assertSame(1, $this->adminAlertCount());
    }

    public function test_settings_save_validates_the_admin_id_and_send_a_test_alert(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(TelegramSettings::class)->set('adminChatId', 'abc')->call('save')->assertHasErrors('adminChatId');
        Livewire::test(TelegramSettings::class)->call('testAdmin');
        $this->assertSame(0, $this->adminAlertCount(), 'Nothing is sent without a saved id.');

        Livewire::test(TelegramSettings::class)->set('adminChatId', (string) self::ADMIN_ID)->call('save')->assertHasNoErrors()->call('testAdmin');
        $this->assertSame((string) self::ADMIN_ID, Setting::get(TelegramAdminAlerts::SETTING_KEY));
        $this->assertSame(1, $this->adminAlertCount());

        Livewire::test(TelegramSettings::class)->set('adminChatId', '')->call('save');
        $this->assertNull(Setting::get(TelegramAdminAlerts::SETTING_KEY));
    }

    public function test_only_admins_reach_the_telegram_settings(): void
    {
        $this->actingAs(User::factory()->sales()->create());
        Livewire::test(TelegramSettings::class)->assertForbidden();
    }

    public function test_connection_test_saves_the_bot_identity_and_group_read_flag(): void
    {
        Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'first_name' => 'CRM Bot', 'username' => 'crm_test_bot', 'can_read_all_group_messages' => false]])]);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(TelegramSettings::class)->call('test');

        $this->assertSame('123456', Setting::get('telegram.bot_id'));
        $this->assertSame('CRM Bot', Setting::get('telegram.bot_name'));
        $this->assertTrue(TelegramContact::query()->where('telegram_user_id', 123456)->value('is_bot'));
        $this->assertFalse(app(TelegramClient::class)->canReadAllGroupMessages());
    }

    public function test_group_message_records_chat_id_sender_and_bot_senders(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => true])]);
        $chat = ['id' => -100777, 'type' => 'supergroup', 'title' => 'Acme group'];

        $this->postJson(route('telegram.webhook'), ['update_id' => 9001, 'message' => ['message_id' => 1, 'chat' => $chat, 'from' => ['id' => 4242, 'is_bot' => false, 'first_name' => 'Ali', 'username' => 'ali'], 'text' => 'hello']], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $row = TelegramChat::query()->where('chat_id', -100777)->sole();
        $this->assertSame([4242, false, 'Ali @ali'], [$row->last_from_id, $row->last_from_is_bot, $row->last_from_name]);

        $this->postJson(route('telegram.webhook'), ['update_id' => 9002, 'message' => ['message_id' => 2, 'chat' => $chat, 'from' => ['id' => 999, 'is_bot' => true, 'first_name' => 'Helper', 'username' => 'helper_bot'], 'text' => 'beep']], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $row->refresh();
        $this->assertSame([999, true], [$row->last_from_id, $row->last_from_is_bot]);
        $bot = TelegramContact::query()->where('telegram_user_id', 999)->sole();
        $this->assertTrue($bot->is_bot);
        $this->assertSame(0, $bot->memberships()->count(), 'A bot never becomes a member.');
    }

    public function test_chats_list_shows_ids_and_filters_by_type(): void
    {
        $chats = app(TelegramChats::class);
        $chats->observeChat(['id' => -1001, 'type' => 'supergroup', 'title' => 'Alpha group'], true, ['id' => 31337, 'first_name' => 'Zoya']);
        $chats->observeChat(['id' => -1002, 'type' => 'channel', 'title' => 'Beta channel']);
        $chats->observeChat(['id' => 9090, 'type' => 'private', 'first_name' => 'Pavel']);

        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ChatsIndex::class)
            ->assertSee('Alpha group')->assertSee('-1001')->assertSee('31337')->assertSee('Zoya')
            ->assertSee('Beta channel')->assertDontSee('Pavel')
            ->set('kind', 'group')->assertSee('Alpha group')->assertDontSee('Beta channel')
            ->set('kind', 'channel')->assertSee('Beta channel')->assertDontSee('Alpha group')
            ->set('kind', 'person')->assertSee('Pavel')->assertSee('9090')
            ->set('kind', '')->set('search', '31337')->assertSee('Alpha group')->assertDontSee('Beta channel');

        $this->assertSame(TelegramChatType::Private, TelegramChat::query()->where('chat_id', 9090)->sole()->type);
    }

    public function test_people_filter_is_for_admins_only(): void
    {
        app(TelegramChats::class)->observeChat(['id' => 9091, 'type' => 'private', 'first_name' => 'Hidden Person']);
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(ChatsIndex::class)->set('kind', 'person')->assertDontSee('Hidden Person');
    }
}
