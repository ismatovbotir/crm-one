<?php

namespace Tests\Feature;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\RecordPayment;
use App\Actions\Offers\SubmitOfferForApproval;
use App\Actions\Offers\UpdateOfferLine;
use App\Actions\Orders\ConfirmOrder;
use App\Enums\NotificationEvent;
use App\Livewire\Notifications\Index;
use App\Livewire\Notifications\Preferences;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\TelegramLink;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\OfferExpiring;
use App\Notifications\PaymentReceived;
use App\Services\Notifications\NotificationPreferences;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Roadmap step 4: notification center with per-user channel preferences and the key events.
 */
class NotificationCenterTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_preferences_defaults_saving_and_telegram_availability(): void
    {
        $user = User::factory()->sales()->create();
        $preferences = app(NotificationPreferences::class);

        $this->assertSame(['database', 'mail'], $preferences->channels($user, NotificationEvent::ApprovalRequested));
        $this->assertSame(['database'], $preferences->channels($user, NotificationEvent::PaymentReceived));

        Livewire::actingAs($user)->test(Preferences::class)->set('choices.payment_received.mail', true)->set('choices.approval_requested.mail', false)->call('save');
        $user->refresh();
        $this->assertSame(['database', 'mail'], $preferences->channels($user, NotificationEvent::PaymentReceived));
        $this->assertSame(['database'], $preferences->channels($user, NotificationEvent::ApprovalRequested));

        Setting::putMany(['telegram.enabled' => '1', 'telegram.bot_token' => Crypt::encryptString('123:'.str_repeat('A', 35))]);
        TelegramLink::query()->create(['user_id' => $user->id, 'chat_id' => 4242, 'telegram_user_id' => 4242, 'linked_at' => now(), 'verified_at' => now()]);
        $this->assertContains(TelegramChannel::class, $preferences->channels($user, NotificationEvent::LeadCreated));
    }

    public function test_offer_approval_reaches_approvers_and_telegram_is_sent_to_api_telegram_only(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        Setting::putMany(['telegram.enabled' => '1', 'telegram.bot_token' => Crypt::encryptString('123:'.str_repeat('A', 35))]);

        $rep = User::factory()->sales()->create();
        $manager = User::factory()->manager()->create();
        TelegramLink::query()->create(['user_id' => $manager->id, 'chat_id' => 777, 'telegram_user_id' => 777, 'linked_at' => now(), 'verified_at' => now()]);
        $offer = $this->draftOffer($rep);
        $line = $this->addLine($offer, $this->pricedItem('1000.00'), '1');
        app(UpdateOfferLine::class)->handle($line->fresh(), $rep, ['discount_bp' => 1500]);

        app(SubmitOfferForApproval::class)->handle($offer->fresh(), $rep);

        $this->assertSame(1, $manager->notifications()->count());
        $this->assertSame(0, $rep->notifications()->count(), 'The requester is not notified.');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.telegram.org/') && (int) $request['chat_id'] === 777 && str_contains((string) $request['text'], $offer->displayNumber()));
    }

    public function test_payment_received_and_deadline_scan_once(): void
    {
        Notification::fake();
        $rep = User::factory()->sales()->create();
        $accountant = User::factory()->accountant()->create();
        $order = $this->simpleOrder($rep, '1');
        app(ConfirmOrder::class)->handle($order, $rep, true);
        $payment = app(RecordPayment::class)->handle(['customer_id' => $order->customer_id, 'amount_minor' => 50_000, 'currency' => 'UZS', 'paid_on' => now()->toDateString(), 'method' => 'cash', 'payer_name' => 'Buyer'], $accountant);
        app(AllocatePayment::class)->handle($payment, $order->fresh(), 50_000, $accountant);
        Notification::assertSentTo($rep, PaymentReceived::class);

        $offer = Offer::query()->sole();
        $offer->forceFill(['status' => 'sent', 'valid_until' => now()->addDay()->toDateString()])->saveQuietly();
        app(Notifier::class)->scanDeadlines();
        app(Notifier::class)->scanDeadlines();
        Notification::assertSentToTimes($rep, OfferExpiring::class, 1);
    }

    public function test_center_lists_marks_read_and_opens_only_local_links(): void
    {
        $user = User::factory()->manager()->create();
        $user->notify(new ApprovalRequested('offer', 'OF-2026-00009', 'Sam', '/offers'));
        $user->notify(new ApprovalRequested('tender_award', 'TR-1', 'Ann', 'https://evil.example/phish'));

        $this->actingAs($user)->get(route('dashboard'))->assertSee('data-notification-bell', false)->assertSee(trans_choice('notifications.unread_count', 2, ['count' => 2]));

        $component = Livewire::actingAs($user)->test(Index::class)->assertSee('OF-2026-00009')->assertSee(__('notifications.kinds.offer'));
        $evil = $user->notifications()->get()->first(fn ($n) => $n->data['url'] === 'https://evil.example/phish');
        $component->call('open', $evil->id)->assertRedirect(route('notifications.index'));
        $component->call('markAllRead');
        $this->assertSame(0, $user->unreadNotifications()->count());

        $other = User::factory()->manager()->create();
        Livewire::actingAs($other)->test(Index::class)->call('markRead', $evil->id)->assertNotFound();
    }
}
