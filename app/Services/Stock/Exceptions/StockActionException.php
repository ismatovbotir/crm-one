<?php

namespace App\Services\Stock\Exceptions;

use RuntimeException;

/**
 * A business rule refused a stock action (receipts, delivery notes, serials, imports). The message
 * is translated from stock.errors.<key>; Livewire screens show it as a flash error.
 */
class StockActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('stock.errors.'.$key, $params));
    }
}
