<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\GoodsReceipt;

/**
 * Stock arrived for queued service tickets (Spec 09 section 15): sent to the service team when a
 * goods receipt posts an item that "waiting in queue" tickets need. Nothing changes automatically.
 */
class ServiceStockArrived extends CrmNotification
{
    /**
     * @param  list<string>  $tickets  ticket numbers, queue order
     */
    public function __construct(public GoodsReceipt $receipt, public array $tickets) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ServiceTicket;
    }

    public function messageKey(): string
    {
        return 'service_stock_arrived';
    }

    protected function params(string $locale): array
    {
        return ['receipt' => (string) $this->receipt->number, 'tickets' => implode(', ', $this->tickets)];
    }

    protected function path(): string
    {
        return route('service.tickets', ['queue' => 'waiting'], false);
    }
}
