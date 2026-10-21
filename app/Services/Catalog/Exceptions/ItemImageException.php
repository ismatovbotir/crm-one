<?php

namespace App\Services\Catalog\Exceptions;

use RuntimeException;

/**
 * An item picture was refused (Spec 01 section 15). Message from catalog.images.errors.<key>.
 */
class ItemImageException extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $key, public readonly array $params = [])
    {
        parent::__construct(__('catalog.images.errors.'.$key, $params));
    }
}
