<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;

/**
 * Something waits for the recipient's approval (roadmap step 4): an offer discount, a tender
 * publication or a tender award. `kind` selects the wording.
 */
class ApprovalRequested extends CrmNotification
{
    /**
     * @param  'offer'|'tender_publication'|'tender_award'  $kind
     */
    public function __construct(public string $kind, public string $number, public string $requester, public string $link) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::ApprovalRequested;
    }

    protected function params(string $locale): array
    {
        return ['what' => __('notifications.kinds.'.$this->kind, [], $locale), 'number' => $this->number, 'requester' => $this->requester];
    }

    protected function path(): string
    {
        return $this->link;
    }

    public function toArray(object $notifiable): array
    {
        return [...parent::toArray($notifiable), 'kind' => $this->kind];
    }
}
