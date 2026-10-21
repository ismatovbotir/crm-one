<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;

/**
 * Daily digest for the accountants (Spec 06 section 4.5): how many schedule items newly reached an
 * overdue reminder stage today; the details are on the receivables page.
 */
class PaymentOverdueDigest extends CrmNotification
{
    public function __construct(public int $items) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::PaymentOverdue;
    }

    public function messageKey(): string
    {
        return 'payment_overdue_digest';
    }

    protected function params(string $locale): array
    {
        return ['count' => $this->items];
    }

    protected function path(): string
    {
        return route('receivables.index', [], false);
    }
}
