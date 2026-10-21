<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;

/**
 * The user's Telegram link changed (Spec 03 section 17.10): linked by an administrator (pending),
 * confirmed, verified in person or unlinked. In-app and e-mail (event `account_security`).
 */
class TelegramLinkChanged extends CrmNotification
{
    /**
     * @param  string  $change  linked_pending|confirmed|verified_in_person|unlinked
     */
    public function __construct(public string $change, public string $username, public string $by) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::AccountSecurity;
    }

    public function messageKey(): string
    {
        return 'telegram_link_'.$this->change;
    }

    /**
     * Always e-mail (when deliverable) and in-app, whatever the preferences: account security.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return method_exists($notifiable, 'hasDeliverableEmail') && $notifiable->hasDeliverableEmail() ? ['database', 'mail'] : ['database'];
    }

    protected function params(string $locale): array
    {
        return ['username' => $this->username, 'by' => $this->by];
    }

    protected function path(): string
    {
        return route('cabinet', [], false);
    }
}
