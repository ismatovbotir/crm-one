<?php

namespace App\Services\Tenders\Exceptions;

use RuntimeException;

/**
 * A business rule refused a tender or counterparty action. The message is translated from
 * tenders.errors.<key>; Livewire screens show it as a flash error.
 */
class TenderActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('tenders.errors.'.$key, $params));
    }
}
