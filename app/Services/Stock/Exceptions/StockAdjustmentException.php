<?php

namespace App\Services\Stock\Exceptions;

use RuntimeException;

/**
 * A business rule refused a stock adjustment action (Spec 06 section 21.4). The message is
 * translated from stock_adjustments.errors.<key>; Livewire screens show it as a flash error.
 */
class StockAdjustmentException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('stock_adjustments.errors.'.$key, $params));
    }
}
