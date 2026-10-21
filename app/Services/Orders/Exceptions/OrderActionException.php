<?php

namespace App\Services\Orders\Exceptions;

use RuntimeException;

/**
 * A business rule refused an order action. The message is translated from orders.errors.<key>;
 * Livewire screens show it as a flash error.
 */
class OrderActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('orders.errors.'.$key, $params));
    }
}
