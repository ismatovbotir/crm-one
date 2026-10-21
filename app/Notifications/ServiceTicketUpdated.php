<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\ServiceTicket;

/**
 * Status change or public note on a repair request, for the customer's portal users (Spec 09 §8):
 * shown in the portal, by e-mail when the address is deliverable, on Telegram when linked.
 * Internal notes never reach this notification.
 */
class ServiceTicketUpdated extends CrmNotification
{
    public function __construct(public ServiceTicket $ticket, public ?string $note = null) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ServiceTicket;
    }

    protected function params(string $locale): array
    {
        $this->ticket->loadMissing('asset.item');

        return [
            'number' => $this->ticket->number,
            'asset' => $this->ticket->asset->displayName().' · '.$this->ticket->asset->serial_raw,
            'status' => __('service.ticket_statuses.'.$this->ticket->status->value, [], $locale),
            'note' => (string) $this->note,
        ];
    }

    public function messageKey(): string
    {
        return 'service_ticket_customer';
    }

    protected function path(): string
    {
        return route('portal.equipment.show', $this->ticket->asset, false);
    }
}
