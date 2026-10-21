<?php

namespace App\Services\Import\Exceptions;

use RuntimeException;

/**
 * A data import file was refused (roadmap step 7). Message from import.errors.<key>.
 */
class ImportException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('import.errors.'.$key, $params));
    }
}
