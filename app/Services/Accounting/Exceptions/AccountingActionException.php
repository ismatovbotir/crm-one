<?php

namespace App\Services\Accounting\Exceptions;

use RuntimeException;

/**
 * A business rule refused an accounting action (payments, invoices, periods). The message is
 * translated from accounting.errors.<key>; Livewire screens show it as a flash error.
 */
class AccountingActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('accounting.errors.'.$key, $params));
    }
}
