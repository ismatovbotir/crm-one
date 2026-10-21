<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\LeadFollowUp;

/**
 * A due lead follow-up (Spec 04 section 16), sent once by `leads:remind`.
 */
class LeadFollowUpDue extends CrmNotification
{
    public function __construct(public LeadFollowUp $followUp) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::FollowUpDue;
    }

    protected function params(string $locale): array
    {
        return ['number' => (string) $this->followUp->lead?->number, 'note' => (string) ($this->followUp->note ?? '')];
    }

    protected function path(): string
    {
        return route('leads.show', $this->followUp->lead_id, false);
    }
}
