<?php

namespace App\Services\Auth\Exceptions;

use RuntimeException;

/**
 * A one-time code could not be issued (cooldown); message from auth.otp.<key>.
 */
class OneTimeCodeException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('auth.otp.'.$key, $params));
    }
}
