<?php

namespace App\Services\Telegram\Internal;

use App\Enums\InternalEvent;
use App\Enums\InvoiceStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\BusinessSettings;
use Carbon\CarbonImmutable;

/**
 * Scheduled internal posts (Spec 03 section 23.2), run every five minutes by
 * `telegram:internal-tick`: the daily and weekly reports once their time has passed (Asia/Tashkent)
 * and, once a day, one "payment overdue" post per overdue order. Dedupe keys make every post
 * happen once even if the tick runs late or twice.
 */
class InternalScheduler
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(protected InternalNotifications $notifications, protected ReportBuilder $reports) {}

    /**
     * @return array{daily: int, weekly: int, overdue: int}
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(ReportBuilder::TIMEZONE);
        $now = $now->setTimezone(ReportBuilder::TIMEZONE);
        $counts = ['daily' => 0, 'weekly' => 0, 'overdue' => 0];

        if ($now->format('H:i') >= self::time('telegram.internal.daily_time', '18:00')) {
            $counts['daily'] = $this->notifications->publish(InternalEvent::ReportDaily, null, $this->reports->build('daily', $now), 'report.daily:'.$now->toDateString());
        }

        $day = BusinessSettings::string('telegram.internal.weekly_day');

        if (self::DAYS[$now->dayOfWeekIso - 1] === $day && $now->format('H:i') >= self::time('telegram.internal.weekly_time', '09:00')) {
            $counts['weekly'] = $this->notifications->publish(InternalEvent::ReportWeekly, null, $this->reports->build('weekly', $now), 'report.weekly:'.$now->format('o-W'));
        }

        if ($now->format('H:i') >= self::time('telegram.internal.overdue_scan_time', '09:00')) {
            Order::query()->where('payment_status', OrderPaymentStatus::Overdue)
                ->whereNotIn('status', [OrderStatus::Closed, OrderStatus::Cancelled])
                ->orderBy('id')->each(function (Order $order) use (&$counts): void {
                    $invoice = Invoice::query()->where('order_id', $order->id)->where('status', InvoiceStatus::Issued)->latest('id')->value('number');
                    $counts['overdue'] += $this->notifications->publish(InternalEvent::InvoiceOverdue, $order, ['invoice' => $invoice], 'invoice.overdue:'.$order->id);
                });
        }

        return $counts;
    }

    /** A valid HH:MM setting, else the default. */
    public static function time(string $key, string $default): string
    {
        $value = BusinessSettings::string($key);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }
}
