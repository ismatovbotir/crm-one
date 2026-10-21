<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Delivery;
use App\Models\Order;

/**
 * An order that is not fully paid was shipped (Spec 06 section 29): accountants and the order
 * owner follow up the payment. In-app always; Telegram and e-mail per the user's settings.
 */
class ShippedUnpaid extends CrmNotification
{
    public function __construct(public Order $order, public Delivery $delivery) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ShippedUnpaid;
    }

    protected function params(string $locale): array
    {
        return [
            'number' => $this->order->number,
            'delivery' => $this->delivery->number,
            'customer' => (string) $this->order->customer?->name,
            'paid' => $this->order->money((int) $this->order->paid_minor)->format(),
            'total' => $this->order->money($this->order->gross_minor)->format(),
        ];
    }

    protected function path(): string
    {
        return route('orders.show', ['order' => $this->order, 'tab' => 'payments'], false);
    }
}
