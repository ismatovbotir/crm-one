<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Order;
use App\Support\Money;

/**
 * A payment was allocated to the recipient's order (roadmap step 4).
 */
class PaymentReceived extends CrmNotification
{
    public function __construct(public Order $order, public int $amountMinor) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::PaymentReceived;
    }

    protected function params(string $locale): array
    {
        return ['number' => $this->order->number, 'amount' => Money::of($this->amountMinor, $this->order->currency)->format(), 'status' => __('orders.payment_status.'.$this->order->payment_status->value, [], $locale)];
    }

    protected function path(): string
    {
        return route('orders.show', ['order' => $this->order, 'tab' => 'payments'], false);
    }
}
