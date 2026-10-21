<?php

namespace App\Services\Agreements\Exceptions;

use RuntimeException;

/**
 * A business rule refused an agreement action. The message is translated from
 * agreements.errors.<key>; Livewire screens show it as a flash error.
 */
class AgreementActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('agreements.errors.'.$key, $params));
    }
}
