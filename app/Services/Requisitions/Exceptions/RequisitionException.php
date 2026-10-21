<?php

namespace App\Services\Requisitions\Exceptions;

use RuntimeException;

/**
 * Refused requisition action (Spec 10); message from requisitions.errors.<key>.
 */
class RequisitionException extends RuntimeException
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(public readonly string $key, array $params = [])
    {
        parent::__construct(__('requisitions.errors.'.$key, $params));
    }
}
