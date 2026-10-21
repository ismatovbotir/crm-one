<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\CustomerAsset;

/**
 * Warranty of a unit ends in 30 days (Spec 09 §6): to the customer's portal admins/buyers and to
 * the sales owner (link to the portal page or the customer card).
 */
class WarrantyExpiring extends CrmNotification
{
    public function __construct(public CustomerAsset $asset, public bool $forPortal) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::WarrantyExpiring;
    }

    protected function params(string $locale): array
    {
        $this->asset->loadMissing(['item', 'organization']);

        return [
            'asset' => $this->asset->displayName().' · '.$this->asset->serial_raw,
            'customer' => (string) $this->asset->organization?->name,
            'date' => (string) $this->asset->warranty_until?->toDateString(),
        ];
    }

    protected function path(): string
    {
        return $this->forPortal
            ? route('portal.equipment.show', $this->asset, false)
            : route('service.assets.show', $this->asset, false);
    }
}
