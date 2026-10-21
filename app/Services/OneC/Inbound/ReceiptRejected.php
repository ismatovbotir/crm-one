<?php

namespace App\Services\OneC\Inbound;

use RuntimeException;

/**
 * Rejects one pushed goods receipt inside its transaction: everything of that receipt (including
 * an auto-created supplier or item) is rolled back.
 *
 * @internal
 */
final class ReceiptRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function __construct(public readonly string $field, public readonly string $errorCode, public readonly array $row)
    {
        parent::__construct($errorCode);
    }
}
