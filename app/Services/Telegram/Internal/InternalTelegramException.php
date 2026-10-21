<?php

namespace App\Services\Telegram\Internal;

use RuntimeException;

/**
 * A rule refused an internal-notification setting (message from telegram.internal.errors.<key>).
 */
class InternalTelegramException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('telegram.internal.errors.'.$key, $params));
    }
}
