<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\ServiceTicket;

/**
 * New repair request (Spec 09 §8) for the service team and the customer's sales owner.
 */
class ServiceTicketOpened extends CrmNotification
{
    public function __construct(public ServiceTicket $ticket) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ServiceTicket;
    }

    protected function params(string $locale): array
    {
        $this->ticket->loadMissing(['asset.item', 'organization']);

        return [
            'number' => $this->ticket->number,
            'customer' => (string) $this->ticket->organization?->name,
            'asset' => $this->ticket->asset->displayName().' · '.$this->ticket->asset->serial_raw,
            'status' => $this->ticket->status->label(),
        ];
    }

    protected function path(): string
    {
        return route('service.tickets.show', $this->ticket, false);
    }
}
