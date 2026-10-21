<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Offer;

/**
 * An open offer expires within 2 days (roadmap step 4), sent once per offer version.
 */
class OfferExpiring extends CrmNotification
{
    public function __construct(public Offer $offer) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::OfferExpiring;
    }

    protected function params(string $locale): array
    {
        return ['number' => $this->offer->displayNumber(), 'customer' => (string) $this->offer->customer?->name, 'date' => (string) $this->offer->valid_until?->format('d.m.Y')];
    }

    protected function path(): string
    {
        return route('offers.show', $this->offer, false);
    }
}
