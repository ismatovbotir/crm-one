<?php

namespace Tests\Feature\Portal;

use App\Actions\Customers\ManagePortalAccess;
use App\Livewire\Cabinet;
use App\Livewire\Portal\Login;
use App\Livewire\Settings\Telegram as TelegramSettings;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\AuthEvent;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 03 4.3-4.6, 4.9: the Telegram bot (webhook secret, idempotency, /start linking, one chat per
 * user, /stop, kicked), the Telegram login channel, the cabinet and the admin-only settings.
 */
class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

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
     * @param  array<string, mixed>  $update
     */
    private function webhook(array $update, ?string $secret = self::SECRET): TestResponse
    {
        return $this->postJson(route('telegram.webhook'), $update, $secret !== null ? ['X-Telegram-Bot-Api-Secret-Token' => $secret] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function message(int $updateId, int $chatId, string $text, array $extra = []): array
    {
        return ['update_id' => $updateId, 'message' => ['message_id' => $updateId, 'chat' => ['id' => $chatId, 'type' => 'private'], 'from' => ['id' => $chatId, 'username' => 'tg_user'.$chatId, 'first_name' => 'Tg'], 'text' => $text, ...$extra]];
    }

    private function token(User $user): string
    {
        return app(TelegramLinks::class)->createToken($user)['token'];
    }

    private function portalUser(string $email = 'buyer@client.uz'): User
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create();
        app(ManagePortalAccess::class)->configure($customer, true, null);

        return app(ManagePortalAccess::class)->grant($customer->fresh(), $email, 'Buyer', ['buyer'], null, null, $rep)->user->fresh();
    }

    public function test_webhook_requires_the_secret_and_an_enabled_bot(): void
    {
        $this->webhook($this->message(1, 500, '/start'), null)->assertForbidden();
        $this->webhook($this->message(1, 500, '/start'), 'wrong')->assertForbidden();

        Setting::putMany(['telegram.enabled' => '0']);
        $this->webhook($this->message(1, 500, '/start'))->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_start_with_a_token_links_once_and_updates_are_processed_once(): void
    {
        $user = User::factory()->sales()->create();
        $token = $this->token($user);

        $this->webhook($this->message(10, 777, '/start '.$token))->assertOk();
        $this->webhook($this->message(10, 777, '/start '.$token))->assertOk();

        $link = TelegramLink::query()->where('user_id', $user->id)->sole();
        $this->assertSame(777, (int) $link->chat_id);
        $this->assertSame('tg_user777', $link->username);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.telegram.org/bot') && (int) $request['chat_id'] === 777);
        $this->assertTrue(AuthEvent::query()->where('event', 'telegram_linked')->where('user_id', $user->id)->exists());

        // The token is single use.
        $this->webhook($this->message(11, 778, '/start '.$token))->assertOk();
        $this->assertFalse(TelegramLink::query()->where('chat_id', 778)->exists());
    }

    public function test_one_chat_cannot_be_linked_to_two_users(): void
    {
        $first = User::factory()->sales()->create();
        $second = User::factory()->sales()->create();
        $this->webhook($this->message(20, 900, '/start '.$this->token($first)));

        $this->webhook($this->message(21, 900, '/start '.$this->token($second)));

        $this->assertSame($first->id, TelegramLink::query()->where('chat_id', 900)->value('user_id'));
        $this->assertFalse(TelegramLink::query()->where('user_id', $second->id)->exists());
    }

    public function test_expired_tokens_are_refused(): void
    {
        $user = User::factory()->sales()->create();
        $token = $this->token($user);
        $this->travel(11)->minutes();

        $this->webhook($this->message(30, 901, '/start '.$token));

        $this->assertFalse(TelegramLink::query()->where('user_id', $user->id)->exists());
    }

    public function test_stop_unlinks_and_kicked_marks_the_link_blocked(): void
    {
        $user = User::factory()->sales()->create();
        $other = User::factory()->sales()->create();
        $this->webhook($this->message(40, 902, '/start '.$this->token($user)));
        $this->webhook($this->message(41, 903, '/start '.$this->token($other)));

        $this->webhook($this->message(42, 902, '/stop'));
        $this->assertFalse(TelegramLink::query()->where('user_id', $user->id)->exists());

        $this->webhook(['update_id' => 43, 'my_chat_member' => ['chat' => ['id' => 903], 'new_chat_member' => ['status' => 'kicked']]]);
        $this->assertNotNull(TelegramLink::query()->where('user_id', $other->id)->value('blocked_at'));
    }

    public function test_only_the_users_own_contact_is_stored_as_phone(): void
    {
        $user = $this->portalUser();
        $this->webhook($this->message(50, 904, '/start '.$this->token($user)));

        $this->webhook($this->message(51, 904, '', ['contact' => ['phone_number' => '998901112233', 'user_id' => 12345]]));
        $this->assertNull(TelegramLink::query()->where('user_id', $user->id)->value('phone'));

        $this->webhook($this->message(52, 904, '', ['contact' => ['phone_number' => '998901112233', 'user_id' => 904]]));
        $this->assertSame('+998901112233', TelegramLink::query()->where('user_id', $user->id)->value('phone'));
    }

    public function test_portal_code_can_be_sent_by_telegram_and_used_with_the_phone(): void
    {
        $user = $this->portalUser();
        $this->webhook($this->message(60, 905, '/start '.$this->token($user)));
        $this->webhook($this->message(61, 905, '', ['contact' => ['phone_number' => '+998 90 111 22 33', 'user_id' => 905]]));

        Livewire::test(Login::class)->set('identifier', '+998901112233')->set('channel', 'telegram')->call('send');

        $sent = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->last(fn (HttpRequest $request) => (int) ($request['chat_id'] ?? 0) === 905 && preg_match('/\b\d{6}\b/', (string) $request['text']) === 1);
        $this->assertNotNull($sent);
        preg_match('/\b(\d{6})\b/', (string) $sent['text'], $match);

        Livewire::test(Login::class)->set('identifier', '@tg_user905')->set('code', $match[1])->call('verify')->assertRedirect(route('portal.dashboard'));
        $this->assertSame($user->id, Auth::guard('portal')->id());
    }

    public function test_staff_cabinet_creates_a_deep_link_and_unlinks(): void
    {
        $user = User::factory()->sales()->create();
        $this->actingAs($user)->get(route('cabinet'))->assertOk()->assertSee(__('telegram.cabinet.not_linked'));

        $component = Livewire::actingAs($user)->test(Cabinet::class)->call('createTelegramLink');
        $deepLink = $component->get('deepLink');
        $this->assertStringStartsWith('https://t.me/crm_test_bot?start=', $deepLink);

        $this->webhook($this->message(70, 906, '/start '.substr($deepLink, strlen('https://t.me/crm_test_bot?start='))));
        $this->assertTrue(TelegramLink::query()->where('user_id', $user->id)->exists());

        Livewire::actingAs($user)->test(Cabinet::class)->call('unlinkTelegram');
        $this->assertFalse(TelegramLink::query()->where('user_id', $user->id)->exists());
    }

    public function test_admin_can_force_unlink_from_the_users_list(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->sales()->create();
        $this->webhook($this->message(80, 907, '/start '.$this->token($user)));

        Livewire::actingAs($admin)->test(UsersIndex::class)->assertSee(__('users.telegram_linked'))->call('unlinkTelegram', $user->id);

        $this->assertFalse(TelegramLink::query()->where('user_id', $user->id)->exists());
        $this->assertTrue(AuthEvent::query()->where('event', 'telegram_force_unlinked')->where('user_id', $user->id)->exists());

        Livewire::actingAs($user)->test(UsersIndex::class)->assertForbidden();
    }

    public function test_settings_page_is_admin_only_and_never_shows_the_token(): void
    {
        $this->actingAs(User::factory()->manager()->create())->get(route('settings.telegram'))->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('settings.telegram'))->assertOk()->assertSee(__('telegram.settings.token_set'))->assertDontSee(str_repeat('A', 35));

        Livewire::actingAs($admin)->test(TelegramSettings::class)->set('token', 'not-a-token')->call('save')->assertHasErrors('token');
        Livewire::actingAs($admin)->test(TelegramSettings::class)->set('enabled', false)->call('save')->assertHasNoErrors();
        $this->assertSame('0', Setting::get('telegram.enabled'));
    }
}
