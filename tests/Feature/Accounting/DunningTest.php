<?php

namespace Tests\Feature\Accounting;

use App\Actions\Customers\ManagePortalAccess;
use App\Enums\DunningStage;
use App\Enums\Locale;
use App\Models\Customer;
use App\Models\DunningEvent;
use App\Models\Order;
use App\Models\PaymentScheduleItem;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CustomerPaymentReminder;
use App\Notifications\PaymentDue;
use App\Notifications\PaymentOverdueDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Spec 06 BR-AC.17 / AC-30: payment reminders per schedule item and stage, sent once, the owner
 * always, the accountants as a digest, the customer only when switched on.
 */
class DunningTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private User $rep;

    private Customer $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rep = User::factory()->sales()->create();
        User::factory()->accountant()->create();
        $this->customer = Customer::factory()->ownedBy($this->rep)->create(['currency' => 'UZS', 'preferred_locale' => 'ru']);
        $this->order = $this->simpleOrder($this->rep, '10', '100', $this->customer);
    }

    /** Sets the due date of the order's open schedule item relative to today. */
    private function dueIn(int $days): PaymentScheduleItem
    {
        $item = $this->order->scheduleItems()->firstOrFail();
        $item->forceFill(['due_on' => now((string) config('orders.timezone'))->addDays($days)->toDateString()])->save();

        return $item;
    }

    public function test_stages_follow_the_days_to_the_due_date_and_only_the_highest_is_sent(): void
    {
        $this->assertNull(DunningStage::reached(-4));
        $this->assertSame(DunningStage::PreDue, DunningStage::reached(-3));
        $this->assertSame(DunningStage::PreDue, DunningStage::reached(0));
        $this->assertSame(DunningStage::Due1, DunningStage::reached(1));
        $this->assertSame(DunningStage::Due7, DunningStage::reached(13));
        $this->assertSame(DunningStage::Due30, DunningStage::reached(400));
    }

    public function test_the_owner_is_reminded_once_per_stage_and_the_accountants_get_a_digest(): void
    {
        Notification::fake();
        $item = $this->dueIn(2);

        $this->artisan('accounting:dunning')->assertExitCode(0);

        $event = DunningEvent::query()->sole();
        $this->assertSame([DunningStage::PreDue, 'in_app', $item->id], [$event->stage, $event->channel, $event->schedule_item_id]);
        $this->assertSame($this->order->gross_minor, $event->amount_minor);
        Notification::assertSentTo($this->rep, PaymentDue::class, fn (PaymentDue $note): bool => ! $note->stage->isOverdue() && ! $note->forPortal);
        Notification::assertNothingSentTo(User::query()->where('role', 'accountant')->first());

        $this->artisan('accounting:dunning')->assertExitCode(0);
        $this->assertSame(1, DunningEvent::query()->count(), 'Rerun is idempotent.');
        Notification::assertSentToTimes($this->rep, PaymentDue::class, 1);

        $item = $this->dueIn(-8);
        $this->artisan('accounting:dunning')->assertExitCode(0);

        $this->assertSame([DunningStage::PreDue->value, DunningStage::Due7->value], DunningEvent::query()->orderBy('id')->pluck('stage')->map(fn ($stage) => $stage->value)->all(), 'An item 8 days overdue sends due_7 only, not due_1.');
        Notification::assertSentToTimes($this->rep, PaymentDue::class, 2);
        Notification::assertSentTo(User::query()->where('role', 'accountant')->first(), PaymentOverdueDigest::class, fn (PaymentOverdueDigest $digest): bool => $digest->items === 1);
        $this->assertSame($item->id, DunningEvent::query()->latest('id')->value('schedule_item_id'));
    }

    public function test_paid_items_and_items_without_a_due_date_are_skipped(): void
    {
        Notification::fake();
        $this->dueIn(-10);
        $this->order->forceFill(['paid_minor' => $this->order->gross_minor])->save();
        $this->artisan('accounting:dunning')->assertExitCode(0);
        $this->assertSame(0, DunningEvent::query()->count());

        $this->order->forceFill(['paid_minor' => 0])->save();
        $this->order->scheduleItems()->update(['due_on' => null]);
        $this->artisan('accounting:dunning')->assertExitCode(0);
        $this->assertSame(0, DunningEvent::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_customer_is_reminded_only_when_the_setting_is_on(): void
    {
        Notification::fake();
        $billing = $this->customer->contacts()->create(['first_name' => 'Aziz', 'email' => 'bill@buyer.uz']);
        $this->order->forceFill(['billing_contact_id' => $billing->id, 'document_locale' => 'ru'])->save();
        $this->customer->forceFill(['portal_enabled' => true])->save();
        $finance = app(ManagePortalAccess::class)->grant($this->customer, 'fin@buyer.uz', 'Finance', ['finance'], null, null, $this->rep)->user;
        $this->dueIn(-2);

        $this->artisan('accounting:dunning')->assertExitCode(0);
        $this->assertSame(['in_app'], DunningEvent::query()->pluck('channel')->all(), 'Customer reminders are off by default.');
        Notification::assertNothingSentTo($finance);

        Setting::putMany(['accounting.customer_reminders_enabled' => '1']);
        $this->artisan('accounting:dunning')->assertExitCode(0);

        $email = DunningEvent::query()->where('channel', 'email')->sole();
        $this->assertSame([DunningStage::Due1, 'ru'], [$email->stage, $email->locale]);
        $this->assertStringContainsString('bill@buyer.uz', (string) $email->recipient);
        Notification::assertSentOnDemand(CustomerPaymentReminder::class, fn (CustomerPaymentReminder $note, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'bill@buyer.uz' && $note->language->value === 'ru' && $note->daysOverdue === 2);
        Notification::assertSentTo($finance, PaymentDue::class, fn (PaymentDue $note): bool => $note->forPortal);

        $this->artisan('accounting:dunning')->assertExitCode(0);
        $this->assertSame(2, DunningEvent::query()->count());
        Notification::assertSentToTimes($finance, PaymentDue::class, 1);
    }

    public function test_the_customer_mail_is_written_in_the_document_language_without_bank_details(): void
    {
        $note = new CustomerPaymentReminder($this->order, DunningStage::Due7, 1_500_000, '2026-10-01', 9, Locale::Ru);
        $mail = $note->toMail((object) []);

        $this->assertStringContainsString($this->order->number, $mail->subject);
        $this->assertStringContainsString('просрочка', implode(' ', $mail->introLines));
        $this->assertStringNotContainsString('account', strtolower(implode(' ', $mail->introLines)));
    }

    public function test_the_reminder_letter_lists_due_and_overdue_items_and_respects_access(): void
    {
        $this->dueIn(-4);
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get(route('receivables.reminder.print', ['customer' => $this->customer, 'lang' => 'en']))
            ->assertOk()
            ->assertSeeHtml('data-reminder-document')
            ->assertSee('Payment reminder')
            ->assertSee($this->order->number)
            ->assertSee('4 days overdue');

        $this->actingAs($manager)->get(route('receivables.reminder.print', ['customer' => $this->customer, 'lang' => 'ru']))->assertOk()->assertSee('просрочено 4 дн.');
        $this->actingAs($this->rep)->get(route('receivables.reminder.print', $this->customer))->assertOk()->assertSee($this->order->number);
        $this->actingAs(User::factory()->sales()->create())->get(route('receivables.reminder.print', $this->customer))->assertForbidden();
        $this->actingAs(User::factory()->stockManager()->create())->get(route('receivables.reminder.print', $this->customer))->assertForbidden();
        $this->actingAs($manager)->get(route('receivables.index'))->assertOk()->assertSeeHtml('data-action="print-reminder"');
    }
}
