<?php

namespace App\Enums;

/**
 * Role of a person in a Telegram chat as last seen by the bot (Spec 04 section 17).
 */
enum TelegramMemberRole: string
{
    case Creator = 'creator';
    case Admin = 'admin';
    case Member = 'member';
    case Left = 'left';

    /** Maps a Telegram ChatMember status (creator, administrator, member, restricted, left, kicked). */
    public static function fromTelegram(string $status): self
    {
        return match ($status) {
            'creator' => self::Creator,
            'administrator' => self::Admin,
            'left', 'kicked' => self::Left,
            default => self::Member,
        };
    }

    public function label(): string
    {
        return __('telegram.chats.roles.'.$this->value);
    }
}
