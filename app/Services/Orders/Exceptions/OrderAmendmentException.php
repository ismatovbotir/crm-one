<?php

namespace App\Services\Orders\Exceptions;

use RuntimeException;

/**
 * A business rule refused an order amendment action (Spec 06 section 6.4). The message is
 * translated from order_amendments.errors.<key>; Livewire screens show it as a flash error.
 */
class OrderAmendmentException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('order_amendments.errors.'.$key, $params));
    }
}
