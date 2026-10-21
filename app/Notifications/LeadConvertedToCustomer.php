<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Partner;

/**
 * A lead was converted to a customer by someone else (Spec 04 section 18.6): sent to the lead
 * owner and the customer owner. Event `lead_converted`.
 */
class LeadConvertedToCustomer extends CrmNotification
{
    public function __construct(public Lead $lead, public Partner $customer, public string $by) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::LeadConverted;
    }

    protected function params(string $locale): array
    {
        return ['number' => $this->lead->number, 'name' => $this->customer->name, 'user' => $this->by];
    }

    protected function path(): string
    {
        return route('customers.show', $this->customer, false);
    }
}
