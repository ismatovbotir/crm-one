<?php

namespace Tests\Feature\Leads;

use App\Actions\Customers\ManagePortalAccess;
use App\Actions\Leads\ConvertLeadToCustomer;
use App\Actions\Leads\LeadWorkflow;
use App\Actions\Offers\CreateOffer;
use App\Enums\LeadActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\OfferStatus;
use App\Enums\SaleType;
use App\Enums\SyncStatus;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatType;
use App\Livewire\Customers\PortalAccess;
use App\Livewire\Customers\Show as CustomerShow;
use App\Livewire\Leads\ConvertToCustomer;
use App\Livewire\Leads\Show;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Offer;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\User;
use App\Notifications\LeadConvertedToCustomer;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\OneC\OneCSettings;
use App\Services\Reports\Definitions\SalesFunnel;
use App\Services\Timeline\CustomerTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 04 section 18: "Convert to customer" — full transfer, duplicate matching with the per-field
 * diff, supplier-only organizations, idempotency, Telegram re-pointing, permissions, read-only
 * converted lead, offers re-pointed, 1C hold, pending portal invitation, notifications, metrics.
 */
class LeadConversionTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::factory()->ownedBy($this->rep)->create([
            'company_name' => 'Buxoro Textile',
            'tax_id' => '305123456',
            'industry' => 'Textile',
            'contact_name' => 'Dilnoza Rahimova',
            'contact_position' => 'Buyer',
            'contact_email' => 'dilnoza@buxoro.uz',
            'contact_phone' => '+998901112233',
            'source' => LeadSource::Exhibition,
            'campaign' => 'Expo 2026',
            'locale' => 'ru',
            'est_value_minor' => 5000000,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function convert(Lead $lead, array $input = [], ?User $user = null): array
    {
        return app(ConvertLeadToCustomer::class)->handle($lead, $user ?? $this->rep, ['mode' => 'create', ...$input]);
    }

    public function test_full_transfer_creates_the_customer_with_contact_history_interests_and_first_offer(): void
    {
        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1']);
        $lead = $this->lead();
        $item = $this->pricedItem('1000.00');
        LeadItem::query()->create(['lead_id' => $lead->id, 'item_id' => $item->id, 'quantity' => '3']);
        app(LeadWorkflow::class)->logActivity($lead, $this->rep, LeadActivityType::Call, 'Discussed 3 looms for the new shop');

        Livewire::actingAs($this->rep)->test(ConvertToCustomer::class, ['leadId' => $lead->id])
            ->assertSet('mode', 'create')
            ->assertSet('createOffer', true)
            ->assertSet('invitePortal', false)
            ->assertSet('onecPull', false)
            ->call('convert')
            ->assertHasNoErrors();

        $lead->refresh();
        $customer = Customer::query()->where('tax_id', '305123456')->sole();
        $this->assertSame(LeadStatus::Converted, $lead->status);
        $this->assertSame($customer->id, $lead->customer_id);
        $this->assertSame($this->rep->id, $lead->converted_by);
        $this->assertNotNull($lead->converted_at);
        $this->assertSame('created', $lead->conversion_kind);

        $this->assertSame('Buxoro Textile', $customer->name);
        $this->assertSame('Textile', $customer->industry);
        $this->assertSame($this->rep->id, $customer->owner_id, 'Customer owner = lead owner.');
        $this->assertSame(SaleType::B2b, $customer->sale_type);
        $this->assertSame('ru', $customer->preferred_locale);
        $this->assertSame('exhibition', $customer->acquisition_source);
        $this->assertSame('Expo 2026', $customer->acquisition_campaign);
        $this->assertSame($lead->id, $customer->source_lead_id);

        $contact = Contact::query()->where('partner_id', $customer->id)->sole();
        $this->assertSame('Dilnoza', $contact->first_name);
        $this->assertSame('dilnoza@buxoro.uz', $contact->email);
        $this->assertTrue($contact->is_primary);
        $this->assertSame($contact->id, $lead->contact_id);

        $offer = Offer::query()->where('lead_id', $lead->id)->sole();
        $this->assertSame($customer->id, $offer->customer_id);
        $this->assertSame(1, $offer->lines()->count());

        // History: transition, audit and the lead's call on the customer timeline.
        $this->assertDatabaseHas('status_transitions', ['subject_type' => 'lead', 'subject_id' => $lead->id, 'to_status' => 'converted', 'user_id' => $this->rep->id]);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'lead', 'subject_id' => $lead->id, 'event' => 'converted_to_customer']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'partner', 'subject_id' => $customer->id, 'event' => 'converted_from_lead']);
        $timeline = app(CustomerTimeline::class)->entries($customer, $this->rep);
        $this->assertTrue($timeline->contains(fn (array $entry): bool => str_contains((string) $entry['detail'], 'Discussed 3 looms')));

        // Interests stay visible on the customer card and become offer lines on demand.
        Livewire::actingAs($this->rep)->test(CustomerShow::class, ['customer' => $customer])
            ->assertSee($item->name)->assertSee(__('leads.conversion.interests_title'))
            ->call('createOfferFromInterests');
        $this->assertSame(2, Offer::query()->where('customer_id', $customer->id)->count());

        // 1C (Spec 02 rev. 4): "Sync with 1C" stays off (option not ticked); edits do not queue it.
        $this->assertFalse($customer->sync_with_1c);
        $this->assertSame(0, CustomerOutboxEntry::query()->where('customer_id', $customer->id)->count());
        $customer->update(['city' => 'Bukhara', 'phone' => '+998712223344']);
        $this->assertSame(0, CustomerOutboxEntry::query()->where('customer_id', $customer->id)->count(), 'Edits do not offer a partner without sync.');
        Livewire::actingAs($this->rep)->test(CustomerShow::class, ['customer' => $customer])->call('resync')->assertForbidden();
        $customer->update(['sync_with_1c' => true]);
        $this->assertSame(1, CustomerOutboxEntry::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(SyncStatus::Pending, $customer->fresh()->sync_status);
    }

    public function test_one_c_option_makes_the_new_customer_available_to_pull(): void
    {
        Setting::putMany([OneCSettings::CUSTOMERS_ENABLED_KEY => '1']);
        $result = $this->convert($this->lead(), ['onec_pull' => true, 'sale_type' => 'retail']);

        $this->assertSame(1, CustomerOutboxEntry::query()->where('customer_id', $result['customer']->id)->whereNull('superseded_at')->count());
        $this->assertSame(SaleType::Retail, $result['customer']->sale_type);
    }

    public function test_tax_id_match_blocks_create_and_link_applies_only_chosen_fields(): void
    {
        $existing = Customer::factory()->ownedBy($this->rep)->create(['name' => 'Buxoro Tekstil MChJ', 'tax_id' => '305123456', 'industry' => 'Retail', 'email' => null]);
        $lead = $this->lead();

        try {
            $this->convert($lead);
            $this->fail('A tax-ID match must block creating a duplicate.');
        } catch (LeadActionException $exception) {
            $this->assertSame('tax_id_match', $exception->key);
        }

        $dialog = Livewire::actingAs($this->rep)->test(ConvertToCustomer::class, ['leadId' => $lead->id])
            ->assertSet('mode', 'link')
            ->assertSet('organizationId', $existing->id)
            ->assertSet('fields.name', 'keep')
            ->assertSet('fields.email', 'lead')
            ->assertSet('fields.industry', 'keep')
            ->assertSee('Buxoro Tekstil MChJ');
        $dialog->call('choose', 'create')->assertSet('mode', 'link', 'Create stays unavailable.');

        $dialog->set('fields.name', 'lead')->set('createOffer', false)->call('convert')->assertHasNoErrors();

        $existing->refresh();
        $this->assertSame('Buxoro Textile', $existing->name, 'Taken from the lead on purpose.');
        $this->assertSame('dilnoza@buxoro.uz', $existing->email, 'Empty field filled.');
        $this->assertSame('Retail', $existing->industry, 'Kept.');
        $this->assertSame('linked', $lead->fresh()->conversion_kind);
        $this->assertSame(1, Partner::withTrashed()->where('tax_id', '305123456')->count());
    }

    public function test_user_who_cannot_edit_the_customer_cannot_overwrite_its_data(): void
    {
        $other = User::factory()->sales()->create();
        $existing = Customer::factory()->ownedBy($other)->create(['name' => 'Old Name', 'tax_id' => '305123456']);
        $lead = $this->lead();

        Livewire::actingAs($this->rep)->test(ConvertToCustomer::class, ['leadId' => $lead->id])
            ->assertSet('mode', 'link')->assertSet('fields.name', 'keep')->assertSee(__('leads.conversion.diff_read_only'));

        try {
            $this->convert($lead, ['mode' => 'link', 'partner_id' => $existing->id, 'fields' => ['name' => 'lead']]);
            $this->fail('Overwriting needs the right to edit the customer.');
        } catch (LeadActionException $exception) {
            $this->assertSame('customer_update_denied', $exception->key);
        }

        $this->convert($lead, ['mode' => 'link', 'partner_id' => $existing->id, 'fields' => ['name' => 'keep']]);
        $this->assertSame('Old Name', $existing->fresh()->name);
        $this->assertSame($other->id, $existing->fresh()->owner_id, 'Owner is never changed silently.');
    }

    public function test_supplier_only_organization_gets_the_customer_role_instead_of_a_duplicate(): void
    {
        $supplier = Counterparty::factory()->create(['name' => 'Delta Supply', 'tax_id' => '303333333']);
        $organization = $supplier->organization;
        $lead = $this->lead(['tax_id' => '303333333', 'company_name' => 'Delta Supply LLC']);

        Livewire::actingAs($this->rep)->test(ConvertToCustomer::class, ['leadId' => $lead->id])
            ->assertSet('mode', 'add_role')->assertSet('organizationId', $organization->id)
            ->assertSee(__('leads.conversion.role_supplier_only'))
            ->set('createOffer', false)
            ->call('convert')->assertHasNoErrors();

        $customer = Customer::query()->findOrFail($organization->id);
        $this->assertTrue((bool) $customer->is_supplier, 'The supplier role stays.');
        $this->assertSame('role_added', $lead->fresh()->conversion_kind);
        $this->assertSame(1, Partner::withTrashed()->where('tax_id', '303333333')->count());
        $this->assertSame('Delta Supply', $customer->name, 'Filled name kept by default.');
    }

    public function test_contacts_are_not_duplicated_and_similar_companies_need_confirmation(): void
    {
        $existing = Customer::factory()->ownedBy($this->rep)->create(['name' => 'Somebody Else', 'tax_id' => '301000001']);
        $known = Contact::factory()->create(['partner_id' => $existing->id, 'email' => 'dilnoza@buxoro.uz', 'phone' => null]);
        $lead = $this->lead(['tax_id' => null]);

        try {
            $this->convert($lead);
            $this->fail('A contact match needs a decision.');
        } catch (LeadActionException $exception) {
            $this->assertSame('possible_duplicate', $exception->key);
        }

        $result = $this->convert($lead, ['mode' => 'link', 'partner_id' => $existing->id, 'create_offer' => false]);
        $this->assertSame($known->id, $result['contact']->id, 'Same e-mail: the existing contact is used.');
        $this->assertSame(1, Contact::query()->where('partner_id', $existing->id)->count());

        $second = $this->lead(['tax_id' => null, 'company_name' => 'Brand New Co', 'contact_email' => 'x@brand.uz', 'contact_phone' => $known->phone ?? '+998935550000']);
        Contact::query()->whereKey($known->id)->update(['phone' => '+998935550000']);
        $this->convert($second, ['not_duplicate' => true, 'create_offer' => false]);
        $this->assertSame(1, Customer::query()->where('name', 'Brand New Co')->count());
    }

    public function test_second_attempt_says_already_converted(): void
    {
        $lead = $this->lead();
        $stale = Livewire::actingAs($this->rep)->test(ConvertToCustomer::class, ['leadId' => $lead->id]);
        $this->convert($lead, ['create_offer' => false]);

        try {
            $this->convert(Lead::query()->findOrFail($lead->id));
            $this->fail('Converting twice must be refused.');
        } catch (LeadActionException $exception) {
            $this->assertSame('already_converted', $exception->key);
        }

        $stale->call('convert')->assertRedirect(route('leads.show', $lead));
        $this->assertSame(1, Customer::query()->where('tax_id', '305123456')->count());
        $this->assertSame(1, Lead::query()->find($lead->id)->transitions()->where('to_status', 'converted')->count());
    }

    public function test_telegram_private_chat_and_group_move_to_the_customer(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::putMany(['telegram.enabled' => '1', 'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)), 'telegram.bot_username' => 'crm_test_bot', 'telegram.webhook_secret' => 'webhook-secret-for-tests-0123456789abcdef']);
        $message = fn (int $id, string $text): array => ['update_id' => $id, 'message' => ['message_id' => $id, 'chat' => ['id' => 5001, 'type' => 'private'], 'from' => ['id' => 5001, 'first_name' => 'Dilshod', 'username' => 'dilshod_r', 'language_code' => 'ru'], 'text' => $text]];
        $this->postJson(route('telegram.webhook'), $message(1, 'Need 10 printers'), ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret-for-tests-0123456789abcdef'])->assertOk();
        $lead = Lead::query()->where('telegram_chat_id', 5001)->sole();
        $lead->forceFill(['owner_id' => $this->rep->id, 'tax_id' => '305999888'])->save();

        $result = $this->convert($lead->fresh(), ['create_offer' => false]);
        $customer = $result['customer'];
        $this->assertSame('ru', $customer->preferred_locale, 'Telegram locale.');
        $this->assertSame($customer->id, TelegramChat::query()->where('chat_id', 5001)->value('customer_id'));

        $this->postJson(route('telegram.webhook'), $message(2, 'And 2 scanners'), ['X-Telegram-Bot-Api-Secret-Token' => 'webhook-secret-for-tests-0123456789abcdef'])->assertOk();
        $next = Lead::query()->where('telegram_chat_id', 5001)->latest('id')->first();
        $this->assertNotSame($lead->id, $next->id, 'A converted lead is never reopened; a new lead starts.');
        $this->assertSame($customer->id, $next->customer_id, 'Later messages attach to the customer.');
        $this->assertSame($this->rep->id, $next->owner_id);

        // Group lead.
        $groupLead = $this->lead(['tax_id' => '305777666', 'company_name' => 'Group Co', 'contact_email' => null, 'contact_phone' => null, 'telegram_chat_id' => -100321]);
        $group = TelegramChat::query()->create(['chat_id' => -100321, 'type' => TelegramChatType::Supergroup, 'title' => 'Group Co buyers', 'bot_status' => TelegramBotStatus::Member, 'first_seen_at' => now(), 'lead_id' => $groupLead->id]);
        $groupCustomer = $this->convert($groupLead, ['create_offer' => false])['customer'];
        $this->assertSame($groupCustomer->id, $group->fresh()->customer_id);
    }

    public function test_permissions_admin_reads_only_and_reps_convert_only_their_leads(): void
    {
        $lead = $this->lead();
        $admin = User::factory()->admin()->create();
        $otherRep = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();

        $this->assertFalse($admin->can('convertToCustomer', $lead));
        $this->assertFalse($otherRep->can('convertToCustomer', $lead));
        $this->assertTrue($manager->can('convertToCustomer', $lead));
        $this->assertTrue($this->rep->can('convertToCustomer', $lead));
        Livewire::actingAs($admin)->test(ConvertToCustomer::class, ['leadId' => $lead->id])->assertForbidden();
        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $lead])->assertSee('data-action="convert-customer"', false)
            ->call('openPanel', 'convert')->assertSee(__('leads.conversion.option_onec'));

        $closed = $this->lead(['status' => LeadStatus::Lost, 'tax_id' => '305000999']);
        $this->assertFalse($this->rep->can('convertToCustomer', $closed));
    }

    public function test_converted_lead_is_read_only_except_notes(): void
    {
        $lead = $this->lead();
        $this->convert($lead, ['create_offer' => false]);
        $lead->refresh();

        $this->assertFalse($this->rep->can('update', $lead));
        $this->assertFalse($this->rep->can('close', $lead));
        $this->assertFalse($this->rep->can('reopen', $lead));
        $this->assertFalse($this->rep->can('convertToCustomer', $lead));
        $this->assertTrue($this->rep->can('logActivity', $lead));

        Livewire::actingAs($this->rep)->test(Show::class, ['lead' => $lead])
            ->assertSee(__('leads.conversion.read_only'))
            ->assertDontSee('data-action="convert-customer"', false)
            ->set('activityType', 'note')->set('activityText', 'Customer asked for a demo')->call('logActivity')->assertHasNoErrors();
        $this->actingAs($this->rep)->get(route('leads.edit', $lead))->assertForbidden();

        try {
            app(LeadWorkflow::class)->assign($lead, User::factory()->manager()->create(), $this->rep);
            $this->fail('Converted leads cannot be reassigned.');
        } catch (LeadActionException $exception) {
            $this->assertSame('read_only', $exception->key);
        }
    }

    public function test_open_offers_of_the_lead_move_to_the_customer(): void
    {
        $old = Customer::factory()->ownedBy($this->rep)->create(['tax_id' => '301111111']);
        $target = Customer::factory()->ownedBy($this->rep)->create(['tax_id' => '302222222', 'name' => 'Target Co']);
        $lead = $this->lead(['customer_id' => $old->id, 'tax_id' => null, 'contact_email' => null, 'contact_phone' => null]);
        $open = app(CreateOffer::class)->handle($this->rep, $old, null, ['lead_id' => $lead->id]);
        $cancelled = app(CreateOffer::class)->handle($this->rep, $old, null, ['lead_id' => $lead->id]);
        $cancelled->forceFill(['status' => OfferStatus::Cancelled])->save();

        $result = $this->convert($lead, ['mode' => 'link', 'partner_id' => $target->id, 'create_offer' => false]);

        $this->assertSame($target->id, $open->fresh()->customer_id);
        $this->assertSame($old->id, $cancelled->fresh()->customer_id, 'Final offers stay with their customer.');
        $this->assertSame([$open->displayNumber()], $result['repointed']);
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'offer', 'subject_id' => $open->id, 'event' => 'customer_repointed']);
    }

    public function test_pending_portal_invitation_is_saved_but_nothing_is_sent(): void
    {
        Notification::fake();
        $result = $this->convert($this->lead(), ['invite_portal' => true, 'create_offer' => false]);
        $customer = $result['customer'];
        $contact = $result['contact']->fresh();

        $this->assertNotNull($contact->portal_invite_requested_at);
        $this->assertSame(0, User::query()->where('email', 'dilnoza@buxoro.uz')->count(), 'No account is created.');

        $manager = User::factory()->manager()->create();
        $customer->forceFill(['portal_enabled' => true])->save();
        Livewire::actingAs($manager)->test(PortalAccess::class, ['customerId' => $customer->id])
            ->assertSee(__('portal.access.pending_title'))
            ->call('prepareInvite', $contact->id)
            ->assertSet('form.email', 'dilnoza@buxoro.uz')
            ->assertSet('form.roles', ['requester']);

        app(ManagePortalAccess::class)->grant($customer, 'dilnoza@buxoro.uz', 'Dilnoza', ['requester'], $contact->id, null, $manager);
        $this->assertNull($contact->fresh()->portal_invite_requested_at);
    }

    public function test_owners_are_notified_and_metrics_count_conversions(): void
    {
        Notification::fake();
        $manager = User::factory()->manager()->create();
        $lead = $this->lead();
        $lead->forceFill(['created_at' => now()->subDays(4)])->save();

        $this->convert($lead, ['create_offer' => false], $manager);

        Notification::assertSentTo($this->rep, LeadConvertedToCustomer::class);
        Notification::assertNotSentTo($manager, LeadConvertedToCustomer::class);

        $tiles = collect(app(DashboardMetrics::class)->sections($this->rep))->flatMap(fn (array $section): array => $section['tiles']);
        $tile = $tiles->first(fn ($tile): bool => is_array($tile) && ($tile['label'] ?? null) === __('dashboard.tiles.lead_conversion'));
        $this->assertNotNull($tile);
        $this->assertSame('100 %', $tile['value']);
        $this->assertSame(__('dashboard.tiles.lead_conversion_hint', ['days' => 4]), $tile['hint']);

        $row = collect(app(SalesFunnel::class)->rows($this->rep, ['from' => null, 'to' => null]))->sole();
        $this->assertSame(1, $row['customers']);
        $this->assertEquals(4, $row['days_to_convert']);
    }
}
