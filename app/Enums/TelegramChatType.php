<?php

namespace App\Enums;

/**
 * Kind of Telegram chat (Spec 04 section 17). Private chat id = the person's Telegram user id;
 * group, supergroup and channel ids are negative.
 */
enum TelegramChatType: string
{
    case Private = 'private';
    case Group = 'group';
    case Supergroup = 'supergroup';
    case Channel = 'channel';

    public static function fromTelegram(string $type): self
    {
        return self::tryFrom($type) ?? self::Group;
    }

    public function isGroup(): bool
    {
        return $this === self::Group || $this === self::Supergroup;
    }

    public function label(): string
    {
        return __('telegram.chats.types.'.$this->value);
    }
}
