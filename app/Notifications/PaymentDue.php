<?php

namespace App\Notifications;

use App\Enums\DunningStage;
use App\Enums\NotificationEvent;
use App\Models\Order;
use App\Support\Money;

/**
 * A payment is coming due or is overdue (Spec 06 BR-AC.17): to the order owner, and to the customer's
 * portal finance users when customer reminders are on. One message per schedule item and stage.
 */
class PaymentDue extends CrmNotification
{
    public function __construct(public Order $order, public DunningStage $stage, public int $outstandingMinor, public string $dueOn, public int $daysOverdue, public bool $forPortal = false) {}

    public function event(): NotificationEvent
    {
        return $this->stage->isOverdue() ? NotificationEvent::PaymentOverdue : NotificationEvent::PaymentReminder;
    }

    protected function params(string $locale): array
    {
        return [
            'number' => $this->order->number,
            'customer' => (string) $this->order->customer?->name,
            'amount' => Money::of($this->outstandingMinor, $this->order->currency)->format(),
            'date' => $this->dueOn,
            'days' => $this->daysOverdue,
        ];
    }

    protected function path(): string
    {
        return $this->forPortal ? route('portal.orders.show', $this->order, false) : route('orders.show', $this->order, false);
    }
}
