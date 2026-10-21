<?php

namespace Tests\Feature\Service;

use App\Actions\Customers\ManagePortalAccess;
use App\Actions\Service\ManageServiceTicket;
use App\Actions\Stock\ClaimSerials;
use App\Actions\Stock\ManageDeliveryNote;
use App\Enums\AssetSource;
use App\Enums\AssetStatus;
use App\Enums\ServiceTicketStatus;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatType;
use App\Enums\UserRole;
use App\Livewire\Customers\Show;
use App\Livewire\Portal\Equipment as PortalEquipment;
use App\Livewire\Portal\EquipmentShow as PortalEquipmentShow;
use App\Livewire\Service\TicketShow;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\OnecOutboxEvent;
use App\Models\Partner;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\ServiceClaimRefused;
use App\Notifications\ServiceStockArrived;
use App\Notifications\ServiceTicketOpened;
use App\Notifications\ServiceTicketUpdated;
use App\Notifications\WarrantyExpiring;
use App\Services\Equipment\AssetRegister;
use App\Services\Equipment\Exceptions\EquipmentException;
use App\Services\Equipment\ServiceQueue;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Concerns\BuildsStock;
use Tests\TestCase;

/**
 * Spec 09 acceptance criteria: asset sync from delivery notes and backfill, tenant isolation,
 * status transitions, warranty, duplicate-serial claim refusal, notifications (portal, Telegram
 * group via api.telegram.org only), permissions, the 1C outbox for billable repairs, reminders.
 */
class CustomerEquipmentTest extends TestCase
{
    use BuildsStock, RefreshDatabase;

    private User $stock;

    private User $rep;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stock = User::factory()->stockManager()->create();
        $this->rep = User::factory()->sales()->create();
        $this->main = Warehouse::factory()->create(['name' => 'Main']);
    }

    /** Sells serials through the real delivery flow; returns the customer. */
    private function sell(array $serials, int $warrantyMonths = 12): Customer
    {
        $item = $this->serializedItem($this->main, '10', ['warranty_months' => $warrantyMonths, 'name' => 'Printer P1']);
        $this->receiveSerials($this->stock, $this->main, $item, $serials);
        $order = $this->orderInFulfilment($this->rep, $item, (string) count($serials));
        $delivery = app(ManageDeliveryNote::class)->create($order, $this->rep, $this->main->id);
        app(ClaimSerials::class)->handle($delivery->lines()->sole(), $serials, $this->stock);
        $this->packAndPost($delivery, $this->stock);

        return $order->customer;
    }

    private function portalUser(Customer $customer, array $roles = ['buyer'], string $email = 'buyer@client.uz'): User
    {
        $customer->forceFill(['portal_enabled' => true])->save();

        return app(ManagePortalAccess::class)->grant($customer, $email, 'Client user', $roles, null, null, $this->rep)->user;
    }

    private function actAsPortal(User $user): void
    {
        $this->actingAs($user, 'portal');
        Auth::shouldUse('web');
        // Livewire::test() skips the portal middleware: set the context it would set.
        app(PortalContext::class)->set($user, $user->activeMembership());
    }

    /** AC-1, AC-4 */
    public function test_posting_a_delivery_note_creates_verified_assets_with_warranty(): void
    {
        $customer = $this->sell(['PR-001', 'PR-002'], 12);

        $assets = CustomerAsset::query()->where('partner_id', $customer->id)->orderBy('serial_normalized')->get();
        $this->assertSame(['PR-001', 'PR-002'], $assets->pluck('serial_normalized')->all());
        $asset = $assets->first();
        $this->assertSame(AssetSource::Sale, $asset->source);
        $this->assertSame(AssetStatus::InUse, $asset->status);
        $this->assertTrue($asset->isVerified());
        $this->assertNotNull($asset->delivery_id);
        $this->assertSame(now()->addMonths(12)->toDateString(), $asset->warranty_until->toDateString());
        $this->assertSame('sold', $asset->events()->first()->event);

        // Warranty from item months when the registry has no date (Spec 09 §6).
        $serial = ItemSerial::query()->where('serial_normalized', 'PR-002')->sole();
        $serial->forceFill(['warranty_until' => null])->save();
        $this->assertSame(now()->startOfDay()->addMonths(12)->toDateString(), AssetRegister::warrantyFor($serial->fresh())?->toDateString());
    }

    /** AC-5: a serial owned by another organization is refused neutrally; staff alerted; no leak. */
    public function test_claiming_another_organizations_serial_is_refused_neutrally(): void
    {
        Notification::fake();
        $engineer = User::factory()->role(UserRole::ServiceEngineer)->create();
        $owner = $this->sell(['OWN-777']);
        $other = Customer::factory()->ownedBy($this->rep)->create(['name' => 'Other Co']);
        $buyer = $this->portalUser($other);
        $this->actAsPortal($buyer);

        Livewire::test(PortalEquipment::class)
            ->set('adding', true)->set('form.serial', 'own-777')->set('form.model_name', 'Printer')
            ->call('register')
            ->assertHasErrors('form.serial')
            ->assertSee(__('service.errors.claim_refused'))
            ->assertDontSee($owner->name);

        $this->assertSame(0, CustomerAsset::query()->where('partner_id', $other->id)->count());
        Notification::assertSentTo($engineer, ServiceClaimRefused::class);
        $this->assertDatabaseHas('asset_events', ['event' => 'claim_refused']);

        // Own registration of a new serial works and is unverified.
        Livewire::test(PortalEquipment::class)->set('adding', true)->set('form.serial', 'NEW-1')->set('form.model_name', 'Scale S2')->set('form.location', 'Shop 3')->call('register')->assertHasNoErrors();
        $asset = CustomerAsset::query()->where('partner_id', $other->id)->sole();
        $this->assertSame(AssetSource::CustomerRegistered, $asset->source);
        $this->assertFalse($asset->isVerified());
        $this->assertNull($asset->warranty_until, 'Not covered by our warranty.');
    }

    /** AC-2: tenant isolation and view-only roles. */
    public function test_portal_users_see_only_their_organizations_equipment(): void
    {
        $customerA = $this->sell(['ISO-A1']);
        $customerB = Customer::factory()->ownedBy($this->rep)->create(['name' => 'Company B']);
        $assetB = app(AssetRegister::class)->register(Partner::query()->find($customerB->id), ['serial' => 'ISO-B1', 'model_name' => 'Secret model B'], $this->rep, AssetSource::StaffRegistered);
        $assetA = CustomerAsset::query()->where('partner_id', $customerA->id)->sole();

        $viewer = $this->portalUser($customerA, ['viewer'], 'viewer@a.uz');
        $this->actAsPortal($viewer);
        $this->get(route('portal.equipment'))->assertOk()->assertSee('ISO-A1')->assertDontSee('ISO-B1')->assertDontSee('Secret model B');
        $this->get(route('portal.equipment.show', $assetB->uuid))->assertNotFound();
        $this->get(route('portal.equipment.show', $assetA->uuid))->assertOk()->assertDontSee(__('service.portal.report'));
        $csv = $this->get(route('portal.equipment.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('ISO-A1', $csv);
        $this->assertStringNotContainsString('ISO-B1', $csv);

        Livewire::test(PortalEquipmentShow::class, ['asset' => $assetA->uuid])->call('reportProblem')->assertForbidden();
    }

    /** AC-3, AC-6, AC-7, AC-8: full repair cycle with notifications, Telegram group and 1C outbox. */
    public function test_repair_cycle_moves_the_asset_notifies_and_publishes_billable_work(): void
    {
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::putMany(['telegram.enabled' => '1', 'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)), 'telegram.bot_username' => 'crm_test_bot', 'telegram.webhook_secret' => 'secret-secret-secret-secret-123456']);

        $customer = $this->sell(['RC-100'], 0); // no warranty: billable
        TelegramChat::query()->create(['chat_id' => -100555, 'type' => TelegramChatType::Supergroup, 'title' => 'Client group', 'bot_status' => TelegramBotStatus::Member, 'first_seen_at' => now(), 'customer_id' => $customer->id]);
        $engineer = User::factory()->role(UserRole::ServiceEngineer)->create();
        $buyer = $this->portalUser($customer);
        $asset = CustomerAsset::query()->where('partner_id', $customer->id)->sole();

        $this->actAsPortal($buyer);
        Livewire::test(PortalEquipmentShow::class, ['asset' => $asset->uuid])
            ->set('reporting', true)->set('report.problem', 'Paper jam, error E05')->set('report.urgency', 'high')
            ->call('reportProblem')->assertHasNoErrors();

        $ticket = $asset->fresh()->tickets()->sole();
        $this->assertSame(AssetStatus::NeedsRepair, $asset->fresh()->status);
        $this->assertFalse($ticket->under_warranty);
        $this->assertNotNull($ticket->respond_due_at);
        Notification::assertSentTo($engineer, ServiceTicketOpened::class);
        Notification::assertSentTo($this->rep, ServiceTicketOpened::class);

        Auth::guard('portal')->logout();
        $tickets = app(ManageServiceTicket::class);
        $tickets->transition($ticket, ServiceTicketStatus::Accepted, $engineer);
        $this->assertSame(AssetStatus::InRepair, $asset->fresh()->status);
        $this->assertNotNull($ticket->fresh()->repair_due_at);
        Notification::assertSentTo($buyer, ServiceTicketUpdated::class);
        Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/') && ($request->data()['chat_id'] ?? null) == -100555);

        try {
            $tickets->transition($ticket->fresh(), ServiceTicketStatus::Closed, $engineer, ['resolution' => 'x']);
            $this->fail('Accepted cannot jump to closed.');
        } catch (EquipmentException $exception) {
            $this->assertSame('invalid_ticket_transition', $exception->key);
        }

        $tickets->transition($ticket->fresh(), ServiceTicketStatus::Diagnosing, $engineer);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::WaitingForParts, $engineer, ['comment' => 'Fuser unit ordered']);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::InRepair, $engineer);
        $tickets->addPart($ticket->fresh(), ['description' => 'Fuser unit', 'quantity' => '1', 'unit_price' => '250000.00'], $engineer);
        $tickets->setWork($ticket->fresh(), '100000.00', null, $engineer);
        $tickets->addNote($ticket->fresh(), $engineer, 'Internal: customer used non-original paper', true);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::Ready, $engineer);
        $this->assertSame(AssetStatus::RepairedReady, $asset->fresh()->status);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::Closed, $engineer, ['resolution' => 'Fuser replaced']);

        $this->assertSame(AssetStatus::InUse, $asset->fresh()->status);
        $this->assertSame(35000000, $ticket->fresh(['parts'])->totalMinor());
        $event = OnecOutboxEvent::query()->where('event_type', 'service_ticket.closed')->sole();
        $this->assertSame('orders', $event->feed);
        $this->assertSame('350000.00', $event->payload['total']);

        // The customer never sees internal notes.
        $this->actAsPortal($buyer);
        $this->get(route('portal.equipment.show', $asset->uuid))->assertOk()->assertSee('Fuser replaced')->assertDontSee('non-original paper');
        $this->assertGreaterThanOrEqual(6, $asset->events()->count());
    }

    /** Spec 09 section 14: group posts carry number and status only; global and per-customer switches. */
    public function test_telegram_group_updates_are_minimal_and_can_be_switched_off(): void
    {
        Notification::fake();
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        Setting::putMany(['telegram.enabled' => '1', 'telegram.bot_token' => Crypt::encryptString('123456:'.str_repeat('A', 35)), 'telegram.bot_username' => 'crm_test_bot', 'telegram.webhook_secret' => 'secret-secret-secret-secret-123456']);
        $customer = $this->sell(['TG-1'], 0);
        TelegramChat::query()->create(['chat_id' => -100777, 'type' => TelegramChatType::Supergroup, 'title' => 'Group', 'bot_status' => TelegramBotStatus::Member, 'first_seen_at' => now(), 'customer_id' => $customer->id]);
        $engineer = User::factory()->role(UserRole::ServiceEngineer)->create();
        $asset = CustomerAsset::query()->where('partner_id', $customer->id)->sole();
        $tickets = app(ManageServiceTicket::class);
        $ticket = $tickets->open($asset, ['problem' => 'Secret problem description E05'], $engineer, 'staff');

        $tickets->transition($ticket, ServiceTicketStatus::Accepted, $engineer);
        Http::assertSent(fn (HttpRequest $request): bool => ($request->data()['chat_id'] ?? null) == -100777
            && str_contains((string) $request->data()['text'], $ticket->number)
            && ! str_contains((string) $request->data()['text'], 'Secret problem')
            && ! str_contains((string) $request->data()['text'], 'TG-1'));

        // Per customer: off on the Telegram tab (needs the right to edit the customer).
        Livewire::actingAs($this->rep)->test(Show::class, ['customer' => $customer])->call('toggleTelegramServiceUpdates');
        $this->assertFalse($customer->fresh()->telegram_service_updates);
        $sent = count(Http::recorded());
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::Diagnosing, $engineer);
        $this->assertCount($sent, Http::recorded(), 'Nothing posted while the customer switch is off.');

        // Globally off wins even when the customer switch is on again.
        $customer->forceFill(['telegram_service_updates' => true])->save();
        Setting::putMany(['service.telegram_group_updates' => '0']);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::InRepair, $engineer);
        $this->assertCount($sent, Http::recorded());
        Notification::assertSentTo($engineer, ServiceTicketOpened::class);
    }

    /** Spec 09 section 15: "Waiting in queue (out of stock)", FIFO within priority, reorder, stock arrived. */
    public function test_out_of_stock_queue_positions_reorder_and_stock_arrival(): void
    {
        Notification::fake();
        $customer = $this->sell(['QU-1', 'QU-2', 'QU-3'], 0);
        $engineer = User::factory()->role(UserRole::ServiceEngineer)->create();
        $buyer = $this->portalUser($customer);
        $assets = CustomerAsset::query()->where('partner_id', $customer->id)->orderBy('serial_normalized')->get();
        $part = $this->serializedItem($this->main, '0', ['name' => 'Fuser unit']);
        $tickets = app(ManageServiceTicket::class);
        $queue = app(ServiceQueue::class);
        $queued = [];

        foreach ([['QU-1', 'normal'], ['QU-2', 'normal'], ['QU-3', 'high']] as $i => [$serial, $urgency]) {
            $ticket = $tickets->open($assets[$i], ['problem' => 'Broken '.$serial, 'urgency' => $urgency], $engineer, 'staff');
            $tickets->transition($ticket, ServiceTicketStatus::Accepted, $engineer);
            $tickets->transition($ticket->fresh(), ServiceTicketStatus::Diagnosing, $engineer);
            $this->travel(1)->minutes();
            $tickets->transition($ticket->fresh(), ServiceTicketStatus::WaitingForParts, $engineer, ['queue_need' => 'Fuser unit', 'queue_item_id' => $part->id, 'queue_eta' => now()->addDays(5)->toDateString()]);
            $queued[$serial] = $ticket->fresh();
        }

        $this->assertNotNull($queued['QU-1']->queued_at);
        $this->assertSame('Fuser unit', $queued['QU-1']->queue_need);
        $this->assertSame(1, $queue->position($queued['QU-3']), 'Higher priority first.');
        $this->assertSame(2, $queue->position($queued['QU-1']), 'Then first in, first out.');
        $this->assertSame(3, $queue->position($queued['QU-2']));
        Notification::assertSentTo($buyer, ServiceTicketUpdated::class);

        try {
            $queue->reorder($queued['QU-2'], 1, 'x', $engineer);
            $this->fail('A reason is required.');
        } catch (EquipmentException $exception) {
            $this->assertSame('reorder_reason_required', $exception->key);
        }

        Livewire::actingAs($engineer)->test(TicketShow::class, ['ticket' => $queued['QU-2']])
            ->set('queuePosition', '1')->set('queueReason', 'Contract customer, agreed with the manager')->call('reorderQueue')->assertHasNoErrors();
        $this->assertSame(2, $queue->position($queued['QU-2']->fresh()), 'First within its priority (normal), still after high.');
        $this->assertSame(3, $queue->position($queued['QU-1']->fresh()));
        $this->assertDatabaseHas('activity_log', ['subject_type' => 'service_ticket', 'subject_id' => $queued['QU-2']->id, 'event' => 'queue_reordered']);

        Auth::guard('web')->logout();
        $this->actAsPortal($buyer);
        $this->get(route('portal.equipment.show', $assets[0]->uuid))->assertOk()->assertSee(__('service.queue.portal_position', ['position' => 3]));
        Auth::guard('portal')->logout();

        $this->receiveSerials($this->stock, $this->main, $part, ['FU-1']);
        Notification::assertSentTo($engineer, ServiceStockArrived::class);
        $this->assertSame(ServiceTicketStatus::WaitingForParts, $queued['QU-1']->fresh()->status, 'Never an automatic status change.');

        $tickets->transition($queued['QU-3']->fresh(), ServiceTicketStatus::InRepair, $engineer);
        $this->assertNull($queued['QU-3']->fresh()->queued_at);
        $this->assertSame(1, $queue->position($queued['QU-2']->fresh()));
        $this->assertSame(2, $queue->summary()['length']);
    }

    public function test_warranty_repairs_are_free_and_not_published(): void
    {
        $customer = $this->sell(['WR-1'], 24);
        $engineer = User::factory()->role(UserRole::ServiceEngineer)->create();
        $asset = CustomerAsset::query()->where('partner_id', $customer->id)->sole();
        $tickets = app(ManageServiceTicket::class);
        $ticket = $tickets->open($asset, ['problem' => 'Does not power on'], $engineer, 'staff');
        $this->assertTrue($ticket->under_warranty);

        foreach ([ServiceTicketStatus::Accepted, ServiceTicketStatus::Diagnosing, ServiceTicketStatus::InRepair] as $to) {
            $tickets->transition($ticket->fresh(), $to, $engineer);
        }

        $tickets->addPart($ticket->fresh(), ['description' => 'Board', 'quantity' => '1', 'unit_price' => '500000.00'], $engineer);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::Ready, $engineer);
        $tickets->transition($ticket->fresh(), ServiceTicketStatus::Closed, $engineer, ['resolution' => 'Board replaced under warranty']);

        $this->assertSame(0, $ticket->fresh(['parts'])->totalMinor());
        $this->assertSame(0, OnecOutboxEvent::query()->where('event_type', 'service_ticket.closed')->count());
    }

    public function test_asset_status_map_without_a_storage_flag(): void
    {
        $customer = $this->sell(['ST-1']);
        $asset = CustomerAsset::query()->where('partner_id', $customer->id)->sole();
        $register = app(AssetRegister::class);
        $this->assertFalse(Schema::hasColumn('customer_assets', 'in_storage'), 'Spec 09 section 15: the storage flag is gone.');

        try {
            $register->changeStatus($asset->fresh(), AssetStatus::RepairedReady, $this->rep);
            $this->fail('in_use cannot go to repaired_ready.');
        } catch (EquipmentException $exception) {
            $this->assertSame('invalid_transition', $exception->key);
        }

        $register->changeStatus($asset->fresh(), AssetStatus::OutOfService, $this->rep, 'Written off');
        $this->assertSame(AssetStatus::OutOfService, $asset->fresh()->status);
    }

    public function test_permissions_service_team_works_admin_reads_sales_sees_own(): void
    {
        $customer = $this->sell(['PM-1']);
        $asset = CustomerAsset::query()->where('partner_id', $customer->id)->sole();
        $ticket = app(ManageServiceTicket::class)->open($asset, ['problem' => 'Noise when printing'], $this->rep, 'staff');
        $admin = User::factory()->admin()->create();
        $engineer = User::factory()->role(UserRole::ServiceEngineer)->create();
        $otherRep = User::factory()->sales()->create();

        $this->assertTrue($engineer->can('manage', $ticket));
        $this->assertTrue($admin->can('view', $ticket));
        $this->assertFalse($admin->can('manage', $ticket));
        $this->assertTrue($this->rep->can('view', $ticket));
        $this->assertFalse($this->rep->can('manage', $ticket));
        $this->assertFalse($otherRep->can('view', $ticket));

        $this->actingAs($engineer)->get(route('service.tickets'))->assertOk()->assertSee($ticket->number);
        // Spec 09 §7 (2026-10-20): sales open the list but see only their own customers' tickets.
        $this->actingAs($otherRep)->get(route('service.tickets'))->assertOk()->assertDontSee($ticket->number);
        Livewire::actingAs($admin)->test(TicketShow::class, ['ticket' => $ticket])->set('target', 'accepted')->call('moveTicket')->assertForbidden();
        $this->actingAs($engineer)->get(route('service.tickets.print', $ticket))->assertOk()->assertSee(__('documents.service_receipt.title'));
        $this->actingAs($this->rep)->get(route('customers.show', ['customer' => $customer, 'tab' => 'equipment']))->assertOk()->assertSee('PM-1');
    }

    /** AC-9 */
    public function test_warranty_reminder_is_sent_once_thirty_days_before_expiry(): void
    {
        Notification::fake();
        $customer = $this->sell(['WE-1']);
        $buyer = $this->portalUser($customer, ['portal_admin'], 'admin@client.uz');
        $asset = CustomerAsset::query()->where('partner_id', $customer->id)->sole();
        $asset->forceFill(['warranty_until' => now()->addDays(20)->toDateString()])->save();

        $this->artisan('service:warranty-reminders')->assertSuccessful();
        $this->artisan('service:warranty-reminders')->assertSuccessful();

        Notification::assertSentToTimes($buyer, WarrantyExpiring::class, 1);
        Notification::assertSentToTimes($customer->owner, WarrantyExpiring::class, 1);
    }
}
