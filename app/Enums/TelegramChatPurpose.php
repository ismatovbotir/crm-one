<?php

namespace App\Enums;

/**
 * What a Telegram chat is for (Spec 03 section 23): a customer chat (leads, members, company link)
 * or a company group the bot posts internal notifications to. Internal chats never create leads.
 */
enum TelegramChatPurpose: string
{
    case Customer = 'customer';
    case InternalNotifications = 'internal_notifications';

    public function label(): string
    {
        return __('telegram.internal.purpose.'.$this->value);
    }
}
