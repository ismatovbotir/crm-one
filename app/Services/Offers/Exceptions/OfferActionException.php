<?php

namespace App\Services\Offers\Exceptions;

use RuntimeException;

/**
 * A business rule refused an offer action. The message is translated from offers.errors.<key>;
 * Livewire screens show it as a flash error.
 */
class OfferActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('offers.errors.'.$key, $params));
    }
}
