<?php

namespace App\Services\Leads\Exceptions;

use RuntimeException;

/**
 * A business rule refused a lead action. The message is translated from leads.errors.<key>;
 * Livewire screens show it as a flash error.
 */
class LeadActionException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('leads.errors.'.$key, $params));
    }
}
