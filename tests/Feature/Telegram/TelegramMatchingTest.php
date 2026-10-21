<?php

namespace Tests\Feature\Telegram;

use App\Enums\Locale;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatPurpose;
use App\Enums\TelegramChatType;
use App\Livewire\Cabinet;
use App\Livewire\Telegram\Unmatched;
use App\Livewire\Users\Form as UserForm;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\TelegramChatMember;
use App\Models\TelegramContact;
use App\Models\TelegramLink;
use App\Models\User;
use App\Notifications\TelegramLinkChanged;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Telegram\Exceptions\TelegramMatchException;
use App\Services\Telegram\Internal\InternalCommands;
use App\Services\Telegram\TelegramMatching;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 03 section 17.10: default CRM language per user; admin matching of Telegram users with
 * pending verification (code from the bot or "verified in person"), one user per Telegram id,
 * pending links get no notifications and no bot commands, audit and notifications.
 */
class TelegramMatchingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::putMany(['telegram.enabled' => '1', 'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)), 'telegram.bot_username' => 'crm_test_bot', 'telegram.webhook_secret' => 'webhook-secret-for-tests-0123456789abcdef']);
        $this->admin = User::factory()->admin()->create();
    }

    private function contact(int $id, array $attributes = []): TelegramContact
    {
        return TelegramContact::query()->create(['telegram_user_id' => $id, 'first_name' => 'Person '.$id, 'username' => 'person'.$id, 'language_code' => 'ru', 'is_bot' => false, ...$attributes]);
    }

    private function member(TelegramContact $contact, TelegramChat $chat): void
    {
        TelegramChatMember::query()->create(['telegram_chat_id' => $chat->id, 'telegram_contact_id' => $contact->id, 'role' => 'member', 'source' => 'message', 'first_seen_at' => now(), 'last_seen_at' => now()]);
    }

    public function test_list_shows_only_unmatched_people_and_hides_customer_group_members_by_default(): void
    {
        $customerGroup = TelegramChat::query()->create(['chat_id' => -100501, 'type' => TelegramChatType::Supergroup, 'title' => 'Client group', 'bot_status' => TelegramBotStatus::Member, 'first_seen_at' => now()]);
        $internal = TelegramChat::query()->create(['chat_id' => -100502, 'type' => TelegramChatType::Supergroup, 'title' => 'Our team', 'bot_status' => TelegramBotStatus::Member, 'first_seen_at' => now(), 'purpose' => TelegramChatPurpose::InternalNotifications]);
        $free = $this->contact(1001);
        $teamMember = $this->contact(1002);
        $this->member($teamMember, $internal);
        $clientMember = $this->contact(1003);
        $this->member($clientMember, $customerGroup);
        $this->contact(1004, ['is_bot' => true]);
        $linked = $this->contact(1005, ['user_id' => User::factory()->sales()->create()->id]);

        $ids = app(TelegramMatching::class)->unmatched()->pluck('telegram_user_id')->all();
        $this->assertEqualsCanonicalizing([1001, 1002], $ids);
        $this->assertContains(1003, app(TelegramMatching::class)->unmatched('', true)->pluck('telegram_user_id')->all());

        Livewire::actingAs($this->admin)->test(Unmatched::class)->assertSee('@person1001')->assertSee('Our team')->assertDontSee('@person1003')->assertDontSee('@person1005');
        Livewire::actingAs(User::factory()->manager()->create())->test(Unmatched::class)->assertForbidden();
        $this->assertNotNull($free);
        $this->assertNotNull($linked);
    }

    public function test_pending_link_gets_nothing_until_the_user_confirms_with_the_bot_code(): void
    {
        Notification::fake();
        $sales = User::factory()->sales()->create();
        $contact = $this->contact(2001);

        Livewire::actingAs($this->admin)->test(Unmatched::class, ['userId' => $sales->id])->call('link', $contact->id)->assertHasNoErrors();

        $link = TelegramLink::query()->where('user_id', $sales->id)->sole();
        $this->assertTrue($link->isPending());
        $this->assertSame($this->admin->id, $link->linked_by);
        $this->assertFalse(app(NotificationPreferences::class)->telegramAvailable($sales), 'No Telegram notifications while pending.');
        Notification::assertSentTo($sales, TelegramLinkChanged::class, fn (TelegramLinkChanged $n): bool => $n->change === 'linked_pending');
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'user', 'subject_id' => $sales->id, 'event' => 'telegram_matched_pending']);
        $this->assertSame(0, collect(Http::recorded())->count(), 'Nothing is sent automatically.');

        $commands = app(InternalCommands::class);
        $group = TelegramChat::query()->create(['chat_id' => -100777, 'type' => TelegramChatType::Supergroup, 'title' => 'Team', 'bot_status' => TelegramBotStatus::Member, 'first_seen_at' => now(), 'purpose' => TelegramChatPurpose::InternalNotifications]);
        $this->assertSame('refused', $commands->handle(['chat' => ['id' => $group->chat_id, 'type' => 'supergroup'], 'from' => ['id' => 2001], 'text' => '/status']), 'Commands are refused while pending.');

        Livewire::actingAs($sales)->test(Cabinet::class)->assertSee(__('telegram.matching.confirm_title', ['username' => '@person2001']))->call('sendVerificationCode')->assertHasNoErrors();
        $sent = collect(Http::recorded())->map(fn (array $pair): HttpRequest => $pair[0])->first(fn (HttpRequest $r): bool => (int) ($r->data()['chat_id'] ?? 0) === 2001);
        $this->assertNotNull($sent);
        preg_match('/\b(\d{6})\b/', (string) $sent->data()['text'], $match);
        $code = $match[1];

        Livewire::actingAs($sales)->test(Cabinet::class)->set('verificationCode', $code === '000000' ? '111111' : '000000')->call('confirmTelegram')->assertHasErrors('verificationCode');
        Livewire::actingAs($sales)->test(Cabinet::class)->set('verificationCode', $code)->call('confirmTelegram')->assertHasNoErrors();

        $this->assertFalse($link->fresh()->isPending());
        $this->assertSame('bot_code', $link->fresh()->verification_method);
        $this->assertTrue(app(NotificationPreferences::class)->telegramAvailable($sales));
        $this->assertSame('answered', $commands->handle(['chat' => ['id' => $group->chat_id, 'type' => 'supergroup'], 'from' => ['id' => 2001], 'text' => '/status']));
    }

    public function test_one_user_per_telegram_id_portal_links_refused_and_in_person_needs_a_reason(): void
    {
        Notification::fake();
        $first = User::factory()->sales()->create();
        $second = User::factory()->sales()->create();
        $contact = $this->contact(3001);
        $matching = app(TelegramMatching::class);
        $matching->match($contact, $first, $this->admin);

        foreach ([[$contact, $second, 'telegram_taken'], [$this->contact(3002), $first, 'user_has_link']] as [$who, $user, $key]) {
            try {
                $matching->match($who, $user, $this->admin);
                $this->fail('Refused: '.$key);
            } catch (TelegramMatchException $exception) {
                $this->assertSame($key, $exception->key);
            }
        }

        $portal = User::factory()->portal()->create();
        TelegramLink::query()->create(['user_id' => $portal->id, 'chat_id' => 3003, 'telegram_user_id' => 3003, 'linked_at' => now(), 'verified_at' => now()]);
        $portalContact = $this->contact(3003);

        try {
            $matching->match($portalContact, $second, $this->admin);
            $this->fail('Portal ids are refused.');
        } catch (TelegramMatchException $exception) {
            $this->assertSame('linked_to_portal', $exception->key);
        }

        try {
            $matching->forceVerify($first, User::factory()->manager()->create(), 'Checked the phone in the office');
            $this->fail('Only with users.telegram.force_link.');
        } catch (TelegramMatchException $exception) {
            $this->assertSame('force_denied', $exception->key);
        }

        Livewire::actingAs($this->admin)->test(Unmatched::class, ['userId' => $first->id])
            ->set('forceReason', 'short')->call('forceVerify')->assertHasErrors('forceReason')
            ->set('forceReason', 'Checked the phone in the office')->call('forceVerify')->assertHasNoErrors();
        $this->assertSame('in_person', TelegramLink::query()->where('user_id', $first->id)->value('verification_method'));
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'user', 'subject_id' => $first->id, 'event' => 'telegram_link_verified_in_person']);

        Livewire::actingAs($this->admin)->test(Unmatched::class, ['userId' => $first->id])->call('unlink');
        $this->assertNull(TelegramLink::query()->where('user_id', $first->id)->first());
        $this->assertContains(3001, $matching->unmatched()->pluck('telegram_user_id')->all(), 'The person is unmatched again.');
        Notification::assertSentTo($first, TelegramLinkChanged::class, fn (TelegramLinkChanged $n): bool => $n->change === 'unlinked');
    }

    public function test_default_language_is_set_on_the_user_and_used_at_sign_in_and_in_notifications(): void
    {
        $user = User::factory()->sales()->create(['password' => 'secret-password-1']);

        Livewire::actingAs($this->admin)->test(UserForm::class, ['user' => $user])->set('locale', 'ru')->call('save')->assertHasNoErrors();
        $this->assertSame(Locale::Ru, $user->fresh()->locale);
        $this->actingAs($this->admin)->get(route('users.index'))->assertSee(Locale::Ru->label());

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertSee('<html lang="ru"', false);

        $mail = (new TelegramLinkChanged('confirmed', '@me', 'Admin'))->toMail($user->fresh());
        $this->assertSame(__('notifications.messages.telegram_link_confirmed.title', ['username' => '@me', 'by' => 'Admin'], 'ru'), $mail->subject);
    }
}
