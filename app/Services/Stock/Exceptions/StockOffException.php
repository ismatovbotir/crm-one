<?php

namespace App\Services\Stock\Exceptions;

use RuntimeException;

/**
 * A business rule refused a stock-off act action (Spec 06 section 27). The message is translated
 * from stock_off.errors.<key>; Livewire screens show it as a flash error.
 */
class StockOffException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('stock_off.errors.'.$key, $params));
    }
}
