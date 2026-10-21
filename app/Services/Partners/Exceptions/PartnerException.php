<?php

namespace App\Services\Partners\Exceptions;

use RuntimeException;

/**
 * A partner rule refused the action (message from partners.errors.<key>).
 */
class PartnerException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [], public readonly ?int $partnerId = null)
    {
        parent::__construct(__('partners.errors.'.$key, $params));
    }
}
