<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Requisition;

/**
 * Requisition step (Spec 10 §7): to the client side (portal link) or to our sales (staff link).
 * Carries the number, company and line count only: never prices.
 */
class RequisitionUpdated extends CrmNotification
{
    public function __construct(public Requisition $requisition, public string $key, public bool $forPortal) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::Requisition;
    }

    public function messageKey(): string
    {
        return $this->key;
    }

    protected function params(string $locale): array
    {
        return [
            'number' => $this->requisition->number,
            'customer' => (string) $this->requisition->organization?->name,
            'lines' => (int) $this->requisition->lines()->count(),
            'status' => __('requisitions.statuses.'.$this->requisition->status->value, [], $locale),
        ];
    }

    protected function path(): string
    {
        return $this->forPortal
            ? route('portal.requisitions.show', $this->requisition, false)
            : route('requisitions.show', $this->requisition, false);
    }
}
