<?php

namespace Tests\Feature\Leads;

use App\Actions\Customers\ManagePortalAccess;
use App\Enums\LeadSource;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatType;
use App\Enums\TelegramMemberRole;
use App\Livewire\Partners\Form as PartnerForm;
use App\Livewire\Telegram\ChatPanel;
use App\Livewire\Telegram\ChatsIndex;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\TelegramChatMember;
use App\Models\TelegramContact;
use App\Models\TelegramLink;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Notifications\NewTelegramLead;
use App\Notifications\TelegramBotRemoved;
use App\Services\Auth\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 04 section 17: Telegram users vs groups. Webhook fixtures for private, group, supergroup,
 * new member, left member, bot removed and bot messages; one open lead per group; members by
 * Telegram id; manager actions (create company from group, contacts from members with pending
 * portal access, link / unlink / merge, refresh members on demand); tenant isolation.
 */
class TelegramGroupTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret-for-tests-0123456789abcdef';

    private const GROUP = -100200300;

    private int $updateId = 5000;

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
    private function tg(array $update): TestResponse
    {
        return $this->postJson(route('telegram.webhook'), ['update_id' => ++$this->updateId, ...$update], ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function user(int $id, string $first, ?string $username = null, bool $bot = false): array
    {
        return array_filter(['id' => $id, 'is_bot' => $bot, 'first_name' => $first, 'username' => $username, 'language_code' => 'uz'], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function groupMessage(array $from, ?string $text, array $extra = [], int $chatId = self::GROUP, string $type = 'supergroup', string $title = 'Alfa Trade buyers'): TestResponse
    {
        $message = ['message_id' => $this->updateId + 1, 'date' => time(), 'chat' => ['id' => $chatId, 'type' => $type, 'title' => $title], 'from' => $from, ...$extra];

        if ($text !== null) {
            $message['text'] = $text;
        }

        return $this->tg(['message' => $message]);
    }

    public function test_private_and_group_chats_are_told_apart(): void
    {
        Notification::fake();
        User::factory()->sales()->create();

        $this->tg(['message' => ['message_id' => 1, 'chat' => ['id' => 7001, 'type' => 'private', 'first_name' => 'Aziz'], 'from' => $this->user(7001, 'Aziz', 'aziz'), 'text' => 'Need 5 routers']]);
        $this->groupMessage($this->user(7002, 'Bekzod', 'bek'), 'We need 20 laptops', type: 'group', chatId: -4001, title: 'Beta LLC');

        $private = TelegramChat::query()->where('chat_id', 7001)->sole();
        $group = TelegramChat::query()->where('chat_id', -4001)->sole();
        $this->assertSame(TelegramChatType::Private, $private->type);
        $this->assertSame(TelegramChatType::Group, $group->type);
        $this->assertSame('Beta LLC', $group->title);
        $this->assertSame(2, Lead::query()->count());
        $this->assertSame('Beta LLC', Lead::query()->where('telegram_chat_id', -4001)->sole()->company_name);
        $this->assertSame([7001, 7002], TelegramContact::query()->orderBy('telegram_user_id')->pluck('telegram_user_id')->all());
        $this->assertNull(TelegramContact::query()->first()->phone, 'No phone unless the person shares their contact.');
    }

    public function test_group_has_one_open_lead_and_messages_append_with_the_sender_named(): void
    {
        Notification::fake();
        $sales = User::factory()->sales()->create();

        $this->groupMessage($this->user(8001, 'Dilshod', 'dilshod'), 'Price for 10 printers?');
        $this->groupMessage($this->user(8002, 'Malika', 'malika'), 'And 2 scanners please');
        $this->groupMessage($this->user(8001, 'Dilshod'), '/start@crm_test_bot');

        $lead = Lead::query()->sole();
        $this->assertSame(LeadSource::Telegram, $lead->source);
        $this->assertSame(self::GROUP, (int) $lead->telegram_chat_id);
        $this->assertSame('Alfa Trade buyers', $lead->company_name);
        $this->assertSame('Dilshod', $lead->contact_name);
        $this->assertSame($sales->id, $lead->owner_id);
        $chat = TelegramChat::query()->sole();
        $this->assertSame($lead->id, $chat->lead_id);
        $activity = $lead->activities()->where('event', 'lead_activity')->sole();
        $this->assertSame('Malika', $activity->properties['from']);
        $this->assertSame(8002, $activity->properties['telegram_user_id']);
        $this->assertSame(2, TelegramChatMember::query()->where('telegram_chat_id', $chat->id)->count());
        Notification::assertSentTo($sales, NewTelegramLead::class);

        $this->actingAs($sales)->get(route('leads.show', $lead))->assertOk()
            ->assertSee(__('telegram.groups.badge'))->assertSee('Malika')->assertSee('@malika');
    }

    public function test_service_messages_track_joins_and_leaves_and_bots_are_ignored(): void
    {
        $this->groupMessage($this->user(9001, 'Owner'), null, ['new_chat_members' => [$this->user(9002, 'New one', 'newone'), $this->user(9999, 'Spam', 'spam_bot', true)]]);
        $this->groupMessage($this->user(9001, 'Owner'), null, ['left_chat_member' => $this->user(9002, 'New one', 'newone')]);
        $this->groupMessage($this->user(9998, 'Helper', 'helper_bot', true), 'Automated message');

        $this->assertSame(0, Lead::query()->count(), 'Service messages and bot messages never open a lead.');
        $this->assertFalse(TelegramContact::query()->where('telegram_user_id', 9999)->exists());
        $member = TelegramChatMember::query()->whereHas('telegramContact', fn ($q) => $q->where('telegram_user_id', 9002))->sole();
        $this->assertSame(TelegramMemberRole::Left, $member->role);
    }

    public function test_bot_removed_notifies_the_owner_and_bot_added_registers_the_group(): void
    {
        Notification::fake();
        $sales = User::factory()->sales()->create();
        $this->tg(['my_chat_member' => ['chat' => ['id' => self::GROUP, 'type' => 'supergroup', 'title' => 'Alfa'], 'from' => $this->user(1, 'Owner'), 'new_chat_member' => ['status' => 'administrator', 'user' => $this->user(42, 'crm', 'crm_test_bot', true)]]]);
        $this->assertSame(TelegramBotStatus::Admin, TelegramChat::query()->sole()->bot_status);

        $this->groupMessage($this->user(1, 'Owner'), 'Hello, we need cables');
        $this->tg(['my_chat_member' => ['chat' => ['id' => self::GROUP, 'type' => 'supergroup', 'title' => 'Alfa'], 'from' => $this->user(1, 'Owner'), 'new_chat_member' => ['status' => 'kicked', 'user' => $this->user(42, 'crm', 'crm_test_bot', true)]]]);

        $this->assertSame(TelegramBotStatus::Removed, TelegramChat::query()->sole()->bot_status);
        Notification::assertSentTo($sales, TelegramBotRemoved::class);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'telegram_chat', 'event' => 'telegram_bot_status_changed']);
    }

    public function test_chat_member_update_edits_and_supergroup_migration_keep_one_chat(): void
    {
        Notification::fake();
        User::factory()->sales()->create();
        $this->groupMessage($this->user(3001, 'Anvar'), 'First request', type: 'group', chatId: -555);
        $this->tg(['chat_member' => ['chat' => ['id' => -555, 'type' => 'group', 'title' => 'Gamma'], 'from' => $this->user(3001, 'Anvar'), 'new_chat_member' => ['status' => 'administrator', 'user' => $this->user(3002, 'Sardor', 'sardor')]]]);
        $this->tg(['edited_message' => ['message_id' => 77, 'edit_date' => time(), 'chat' => ['id' => -555, 'type' => 'group', 'title' => 'Gamma'], 'from' => $this->user(3001, 'Anvar'), 'text' => 'First request, corrected']]);

        $this->groupMessage($this->user(3001, 'Anvar'), null, ['migrate_to_chat_id' => -100555], chatId: -555, type: 'group');
        $this->groupMessage($this->user(3001, 'Anvar'), 'Now in the supergroup', chatId: -100555);

        $this->assertSame(1, TelegramChat::query()->count());
        $this->assertSame(-100555, TelegramChat::query()->sole()->chat_id);
        $lead = Lead::query()->sole();
        $this->assertSame(-100555, (int) $lead->telegram_chat_id);
        $this->assertSame(2, $lead->activities()->where('event', 'lead_activity')->count(), 'Edit and supergroup message appended.');
        $this->assertSame(TelegramMemberRole::Admin, TelegramChatMember::query()->whereHas('telegramContact', fn ($q) => $q->where('telegram_user_id', 3002))->sole()->role);
    }

    public function test_updates_are_idempotent_and_rate_limited_per_sender(): void
    {
        Notification::fake();
        config(['leads.telegram_rate_per_hour' => 2]);
        $payload = ['update_id' => 999, 'message' => ['message_id' => 1, 'chat' => ['id' => self::GROUP, 'type' => 'supergroup', 'title' => 'Alfa'], 'from' => $this->user(1, 'Spammer'), 'text' => 'Hi']];
        $this->postJson(route('telegram.webhook'), $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->postJson(route('telegram.webhook'), $payload, ['X-Telegram-Bot-Api-Secret-Token' => self::SECRET])->assertOk();
        $this->assertSame(1, TelegramMessage::query()->count());

        foreach (range(1, 4) as $i) {
            $this->groupMessage($this->user(1, 'Spammer'), 'Message '.$i);
        }

        $this->assertSame(1, Lead::query()->sole()->activities()->where('event', 'lead_activity')->count(), 'Two messages per hour for this sender.');
        $this->postJson(route('telegram.webhook'), ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertForbidden();
    }

    public function test_create_company_from_group_links_the_chat_and_later_leads(): void
    {
        Notification::fake();
        $sales = User::factory()->sales()->create();
        $this->groupMessage($this->user(8001, 'Dilshod', 'dilshod'), 'Need printers');
        $lead = Lead::query()->sole();

        $component = Livewire::withQueryParams(['role' => 'customer', 'lead' => $lead->id])->actingAs($sales)->test(PartnerForm::class)
            ->assertSet('form.name', 'Alfa Trade buyers');
        $this->assertStringContainsString('Alfa Trade buyers', $component->get('form.notes'));
        $component->set('form.legal_name', 'Alfa Trade buyers MChJ')->set('form.tax_id', '305000111')->call('save')->assertHasNoErrors();

        $customer = Customer::query()->where('tax_id', '305000111')->sole();
        $chat = TelegramChat::query()->sole();
        $this->assertSame($customer->id, $chat->customer_id);
        $this->assertSame($customer->id, $lead->fresh()->customer_id);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'telegram_chat', 'event' => 'telegram_chat_linked']);

        $lead->forceFill(['status' => 'lost', 'closed_at' => now()])->save();
        $this->groupMessage($this->user(8002, 'Malika'), 'New order request');
        $this->assertSame($customer->id, Lead::query()->latest('id')->first()->customer_id, 'Later messages start leads on the company.');
    }

    public function test_contacts_from_members_with_pending_portal_access_and_conflicts(): void
    {
        Notification::fake();
        $sales = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($sales)->create(['portal_enabled' => true]);
        $other = Customer::factory()->ownedBy($sales)->create(['name' => 'Other Co']);
        $staff = User::factory()->accountant()->create();
        TelegramLink::query()->create(['user_id' => $staff->id, 'chat_id' => 6003, 'telegram_user_id' => 6003, 'linked_at' => now(), 'verified_at' => now()]);

        $this->groupMessage($this->user(6001, 'Dilshod', 'dilshod'), 'Need printers');
        $this->groupMessage($this->user(6002, 'Malika', 'malika'), 'Me too');
        $this->groupMessage($this->user(6003, 'Our accountant'), 'Invoice sent');
        $this->groupMessage($this->user(6002, 'Malika', 'malika'), 'In the other group', chatId: -777, title: 'Other group');

        $chat = TelegramChat::query()->where('chat_id', self::GROUP)->sole();
        TelegramChat::query()->where('chat_id', -777)->update(['customer_id' => $other->id]);
        $ids = TelegramContact::query()->orderBy('telegram_user_id')->pluck('id', 'telegram_user_id');

        $panel = Livewire::actingAs($sales)->test(ChatPanel::class, ['chatId' => $chat->id])
            ->set('linkCustomerId', $customer->id)->call('linkCustomer')
            ->assertSee(__('telegram.groups.conflict', ['companies' => 'Other Co']))
            ->assertSee(__('telegram.groups.staff'));

        $panel->set('selected', [$ids[6001], $ids[6003]])->set('invite', true)->call('createContacts')
            ->assertSee(__('telegram.groups.contacts_created', ['created' => 1, 'invited' => 1, 'skipped' => 1]));

        $person = TelegramContact::query()->where('telegram_user_id', 6001)->sole();
        $this->assertSame($customer->id, $person->contact->partner_id);
        $this->assertSame('dilshod', $person->contact->telegram_username);
        $portalUser = $person->user;
        $this->assertTrue($portalUser->isPortal());
        $this->assertFalse($portalUser->hasDeliverableEmail());
        $membership = $portalUser->portalMemberships()->sole();
        $this->assertNull($membership->activated_at, 'Pending until the first sign-in.');
        $this->assertSame(['viewer'], $membership->roles);
        $this->assertSame(6001, TelegramLink::query()->where('user_id', $portalUser->id)->value('chat_id'));
        $this->assertTrue($portalUser->is(app(PortalLogin::class)->resolve('@dilshod')), 'They can ask for a Telegram login code with their @username.');
        Mail::fake();
        app(PortalLogin::class)->requestCode('tg-6001@telegram.invalid', 'email');
        Mail::assertNothingOutgoing();
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains((string) ($request->data()['text'] ?? ''), 'portal'));
        $this->assertNull(TelegramContact::query()->where('telegram_user_id', 6003)->value('contact_id'), 'Staff are never customer contacts.');
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'telegram_chat', 'event' => 'telegram_member_to_contact']);
    }

    public function test_refresh_members_unlink_and_merge(): void
    {
        Notification::fake();
        $manager = User::factory()->manager()->create();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getChatAdministrators' => Http::response(['ok' => true, 'result' => [['status' => 'creator', 'user' => $this->user(1111, 'Boss', 'boss')], ['status' => 'administrator', 'user' => $this->user(42, 'crm', 'crm_test_bot', true)]]]),
            'api.telegram.org/*/getChatMemberCount' => Http::response(['ok' => true, 'result' => 37]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        $this->groupMessage($this->user(2001, 'Aziz'), 'Hello', chatId: -1001, title: 'Delta team');
        $this->groupMessage($this->user(2002, 'Olim'), 'Hello again', chatId: -1002, title: 'Delta team (old)');
        $keep = TelegramChat::query()->where('chat_id', -1001)->sole();
        $duplicate = TelegramChat::query()->where('chat_id', -1002)->sole();

        Livewire::actingAs($manager)->test(ChatPanel::class, ['chatId' => $keep->id])->call('refreshMembers')
            ->assertSee(__('telegram.groups.refreshed', ['admins' => 1, 'count' => 37]));
        $this->assertSame(37, $keep->fresh()->member_count);
        $this->assertSame(TelegramMemberRole::Creator, TelegramChatMember::query()->whereHas('telegramContact', fn ($q) => $q->where('telegram_user_id', 1111))->sole()->role);

        Livewire::actingAs($manager)->test(ChatsIndex::class)
            ->assertSee('Delta team')->assertSee(__('telegram.chats.privacy_note'))
            ->set('mergeId', $duplicate->id)->set('mergeIntoId', $keep->id)->call('merge')->assertHasNoErrors();
        $this->assertSame($keep->id, $duplicate->fresh()->merged_into_id);
        $this->assertSame(3, TelegramChatMember::query()->where('telegram_chat_id', $keep->id)->count());

        $customer = Customer::factory()->create();
        Livewire::actingAs($manager)->test(ChatPanel::class, ['chatId' => $keep->id])->set('linkCustomerId', $customer->id)->call('linkCustomer')->call('unlinkCustomer');
        $this->assertNull($keep->fresh()->customer_id);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'telegram_chat', 'event' => 'telegram_chat_unlinked']);
    }

    public function test_group_titles_and_names_are_escaped(): void
    {
        Notification::fake();
        $manager = User::factory()->manager()->create();
        $this->groupMessage($this->user(4001, '<img src=x onerror=alert(1)>'), 'Hi', title: '<script>alert(1)</script>');
        $chat = TelegramChat::query()->sole();

        Livewire::actingAs($manager)->test(ChatPanel::class, ['chatId' => $chat->id])
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<img src=x', false);
    }

    public function test_tenant_isolation_for_portal_users_and_other_reps(): void
    {
        Notification::fake();
        $repA = User::factory()->sales()->create();
        $repB = User::factory()->sales()->create();
        $companyA = Customer::factory()->ownedBy($repA)->create(['portal_enabled' => true]);
        $companyB = Customer::factory()->ownedBy($repB)->create(['portal_enabled' => true, 'name' => 'Company B']);
        $this->groupMessage($this->user(5001, 'B buyer'), 'Secret B request', title: 'B private group');
        $chatB = TelegramChat::query()->sole();
        $chatB->forceFill(['customer_id' => $companyB->id])->save();

        $portalA = app(ManagePortalAccess::class)->grant($companyA, 'buyer@a.uz', 'A buyer', ['buyer'], null, null, $repA)->user;
        $this->actingAs($portalA, 'portal');
        Auth::shouldUse('web');
        $this->get(route('portal.dashboard'))->assertOk()->assertDontSee('B private group')->assertDontSee('Secret B request');
        Auth::shouldUse('web');
        $this->get(route('telegram.chats'))->assertRedirect();

        // Staff follow customer visibility (Spec 01 D-7: every commercial role sees all customers),
        // but only the owner side may change the group's company or create its contacts.
        $this->assertTrue($repA->can('view', $chatB));
        $this->assertFalse($repA->can('createContacts', $chatB));
        $this->assertTrue($repB->can('createContacts', $chatB));
        Livewire::actingAs($repA)->test(ChatPanel::class, ['chatId' => $chatB->id])->call('unlinkCustomer')->assertForbidden();
        $this->assertSame($companyB->id, $chatB->fresh()->customer_id);
        $finance = User::factory()->stockManager()->create();
        $this->assertFalse($finance->can('viewAny', TelegramChat::class));
        Auth::guard('portal')->logout();
        $this->actingAs($repB)->get(route('customers.show', ['customer' => $companyB, 'tab' => 'telegram']))->assertOk()->assertSee('B private group');
    }
}
