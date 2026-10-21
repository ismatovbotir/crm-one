<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;

/**
 * A customer tried to register a serial that belongs to another organization (Spec 09 R-1).
 * Staff only; the claimant saw a neutral message.
 */
class ServiceClaimRefused extends CrmNotification
{
    public function __construct(public string $claimant, public string $serial, public ?string $owner) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ServiceTicket;
    }

    protected function params(string $locale): array
    {
        return ['claimant' => $this->claimant, 'serial' => $this->serial, 'owner' => (string) ($this->owner ?? '—')];
    }

    public function messageKey(): string
    {
        return 'service_claim_refused';
    }

    protected function path(): string
    {
        return route('service.assets', ['search' => $this->serial], false);
    }
}
