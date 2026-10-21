<?php

namespace App\Services\Accounting;

use App\Enums\CustomerPortalRole;
use App\Enums\DunningStage;
use App\Enums\Locale;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\DunningEvent;
use App\Models\Order;
use App\Models\PaymentScheduleItem;
use App\Models\User;
use App\Notifications\CustomerPaymentReminder;
use App\Notifications\PaymentDue;
use App\Notifications\PaymentOverdueDigest;
use App\Support\BusinessSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Notification;

/**
 * Payment reminders (Spec 06 BR-AC.17, AC-30): every schedule item with an outstanding amount and a
 * due date reaches a stage (`pre_due`, `due_1`, `due_7`, `due_14`, `due_30`); each stage is sent once
 * per channel, recorded in `dunning_events` (the unique key makes a rerun harmless).
 * - `in_app`: the order owner always, and the accountants get one digest of the newly overdue items;
 * - `email`: only when `accounting.customer_reminders_enabled` is on — the customer's billing contact
 *   by e-mail in the order's document language, and the portal finance users in the portal.
 * Only the highest stage reached is sent, so a first run on old debt does not flood the customer.
 */
class RunDunning
{
    public function __construct(protected OrderPayments $payments) {}

    /**
     * @return array{items: int, internal: int, customer: int, digest: int}
     */
    public function run(): array
    {
        $result = ['items' => 0, 'internal' => 0, 'customer' => 0, 'digest' => 0];
        $today = AccountingPeriods::today();
        $customerEnabled = BusinessSettings::bool('accounting.customer_reminders_enabled');
        $newlyOverdue = 0;

        Order::query()->with(['customer', 'owner', 'billingContact', 'contact'])
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed])
            ->whereHas('scheduleItems', fn ($query) => $query->whereNotNull('due_on'))
            ->orderBy('id')
            ->chunkById(200, function ($orders) use (&$result, &$newlyOverdue, $today, $customerEnabled): void {
                foreach ($orders as $order) {
                    foreach ($this->payments->coverage($order) as $row) {
                        $item = $row['item'];

                        if ($row['outstanding'] <= 0 || $item->due_on === null) {
                            continue;
                        }

                        $daysOverdue = (int) CarbonDays::between($item->due_on->toDateString(), $today->toDateString());
                        $stage = DunningStage::reached($daysOverdue);

                        if ($stage === null) {
                            continue;
                        }

                        $result['items']++;

                        if ($this->internal($order, $item, $stage, $row['outstanding'], $daysOverdue)) {
                            $result['internal']++;
                            $newlyOverdue += $stage->isOverdue() ? 1 : 0;
                        }

                        if ($customerEnabled && $this->customer($order, $item, $stage, $row['outstanding'], $daysOverdue)) {
                            $result['customer']++;
                        }
                    }
                }
            });

        if ($newlyOverdue > 0) {
            $accountants = User::query()->where('is_active', true)->where('role', UserRole::Accountant->value)->get();

            if ($accountants->isNotEmpty()) {
                Notification::send($accountants, new PaymentOverdueDigest($newlyOverdue));
                $result['digest'] = $accountants->count();
            }
        }

        return $result;
    }

    protected function internal(Order $order, PaymentScheduleItem $item, DunningStage $stage, int $outstanding, int $daysOverdue): bool
    {
        $owner = $order->owner;

        if ($owner === null || ! $owner->is_active) {
            return false;
        }

        if (! $this->record($order, $item, $stage, 'in_app', 'user:'.$owner->id, null, $outstanding)) {
            return false;
        }

        $owner->notify(new PaymentDue($order, $stage, $outstanding, $item->due_on->toDateString(), max(0, $daysOverdue)));

        return true;
    }

    protected function customer(Order $order, PaymentScheduleItem $item, DunningStage $stage, int $outstanding, int $daysOverdue): bool
    {
        $locale = $order->preferredDocumentLocale() ?? Locale::default();
        $email = $order->billingContact?->email ?? $order->contact?->email;
        $portalUsers = User::query()->where('is_active', true)->whereHas('portalMemberships', fn ($query) => $query->where('customer_id', $order->customer_id)->where('is_active', true)
            ->where(fn ($roles) => $roles->whereJsonContains('roles', CustomerPortalRole::Finance->value)->orWhereJsonContains('roles', CustomerPortalRole::PortalAdmin->value)))->get();

        if (blank($email) && $portalUsers->isEmpty()) {
            return false;
        }

        $recipient = collect([$email, ...$portalUsers->map(fn (User $user): string => 'user:'.$user->id)->all()])->filter()->implode(', ');

        if (! $this->record($order, $item, $stage, 'email', mb_substr($recipient, 0, 255), $locale->value, $outstanding)) {
            return false;
        }

        $due = $item->due_on->toDateString();

        if (filled($email)) {
            Notification::route('mail', $email)->notify(new CustomerPaymentReminder($order, $stage, $outstanding, $due, max(0, $daysOverdue), $locale));
        }

        foreach ($portalUsers as $user) {
            $user->notify(new PaymentDue($order, $stage, $outstanding, $due, max(0, $daysOverdue), true));
        }

        return true;
    }

    /** True when this (item, stage, channel) was not recorded before; the unique key is the last guard. */
    protected function record(Order $order, PaymentScheduleItem $item, DunningStage $stage, string $channel, ?string $recipient, ?string $locale, int $outstanding): bool
    {
        if (DunningEvent::query()->where('schedule_item_id', $item->id)->where('stage', $stage->value)->where('channel', $channel)->exists()) {
            return false;
        }

        try {
            DunningEvent::query()->create([
                'schedule_item_id' => $item->id,
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'stage' => $stage,
                'channel' => $channel,
                'recipient' => $recipient,
                'locale' => $locale,
                'amount_minor' => $outstanding,
                'currency' => $order->currency,
                'sent_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
