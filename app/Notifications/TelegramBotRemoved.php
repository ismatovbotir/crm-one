<?php

namespace App\Notifications;

use App\Enums\NotificationEvent;
use App\Models\TelegramChat;

/**
 * The bot was removed from a Telegram group (Spec 04 section 17): the sales owner of the group's
 * lead or customer is told, because messages from that group no longer reach the CRM.
 */
class TelegramBotRemoved extends CrmNotification
{
    public function __construct(public TelegramChat $chat) {}

    public function event(): NotificationEvent
    {
        return NotificationEvent::TelegramBotRemoved;
    }

    protected function params(string $locale): array
    {
        return ['group' => $this->chat->displayName()];
    }

    protected function path(): string
    {
        return $this->chat->lead_id !== null
            ? route('leads.show', $this->chat->lead_id, false)
            : ($this->chat->customer_id !== null ? route('customers.show', ['customer' => $this->chat->customer_id, 'tab' => 'telegram'], false) : route('telegram.chats', [], false));
    }
}
