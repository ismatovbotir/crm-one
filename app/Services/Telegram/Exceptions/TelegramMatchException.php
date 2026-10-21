<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * A Telegram matching / verification rule refused the action (message from telegram.matching.errors.<key>).
 */
class TelegramMatchException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('telegram.matching.errors.'.$key, $params));
    }
}
