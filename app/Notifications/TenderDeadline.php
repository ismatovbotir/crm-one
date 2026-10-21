<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\Tender;

/**
 * A published tender closes for bids within 24 hours (roadmap step 4), sent once per tender.
 */
class TenderDeadline extends CrmNotification
{
    public function __construct(public Tender $tender) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::TenderDeadline;
    }

    protected function params(string $locale): array
    {
        return ['number' => (string) $this->tender->number, 'title' => (string) $this->tender->title, 'at' => (string) $this->tender->bids_due_at?->timezone('Asia/Tashkent')->format('d.m.Y H:i')];
    }

    protected function path(): string
    {
        return route('tenders.show', $this->tender, false);
    }
}
