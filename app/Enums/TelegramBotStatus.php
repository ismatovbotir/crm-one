<?php

namespace App\Enums;

/**
 * The bot's own status in a chat (Spec 04 section 17), from `my_chat_member` updates.
 */
enum TelegramBotStatus: string
{
    case Member = 'member';
    case Admin = 'admin';
    case Removed = 'removed';

    /** Maps a Telegram ChatMember status (creator, administrator, member, restricted, left, kicked). */
    public static function fromTelegram(string $status): self
    {
        return match ($status) {
            'creator', 'administrator' => self::Admin,
            'left', 'kicked' => self::Removed,
            default => self::Member,
        };
    }

    public function label(): string
    {
        return __('telegram.chats.bot_statuses.'.$this->value);
    }
}
