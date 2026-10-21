<?php

namespace App\Services\Equipment\Exceptions;

use RuntimeException;

/**
 * Refused equipment or service action (Spec 09); the message comes from service.errors.<key>.
 */
class EquipmentException extends RuntimeException
{
    /**
     * @param  array<string, string>  $params
     */
    public function __construct(public readonly string $key, array $params = [])
    {
        parent::__construct(__('service.errors.'.$key, $params));
    }
}
