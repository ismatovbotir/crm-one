<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Lead;

/**
 * A new lead for the recipient (Spec 04 section 15.2 Telegram leads, leads assigned by someone
 * else): event `lead_created`.
 */
class NewTelegramLead extends CrmNotification
{
    public function __construct(public Lead $lead) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::LeadCreated;
    }

    protected function params(string $locale): array
    {
        return ['number' => $this->lead->number, 'name' => $this->lead->companyLabel(), 'source' => __('leads.sources.'.$this->lead->source->value, [], $locale)];
    }

    protected function path(): string
    {
        return route('leads.show', $this->lead, false);
    }
}
