<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * Telegram Bot API failure (Spec 03 4.6): `reason` is one of bot_blocked, chat_not_found,
 * rate_limited, unauthorized, disabled, failed, migrated (with the new chat id), no_rights,
 * topic_not_found. Never carries message text or codes.
 */
class TelegramException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0, public readonly ?int $migrateToChatId = null)
    {
        parent::__construct('telegram:'.$reason);
    }
}
