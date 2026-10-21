<?php

namespace App\Services\OneC\Inbound;

use Closure;

/**
 * Marks code that applies 1C data (inbound batch processing) so the change log follows
 * Spec 02 section 7.3: creations are not logged per row (the batch row audits them), updates
 * are logged as `synced_from_1c`, deletions seen in 1C as `deleted_in_1c`, price changes keep
 * `price_changed`. The causer is null (system).
 */
final class OneCSyncContext
{
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Closure $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * @param  array<string, mixed>  $changed
     */
    public static function eventFor(string $modelEvent, array $changed): string
    {
        if (array_key_exists('external_deleted_at', $changed) && $changed['external_deleted_at'] !== null) {
            return 'deleted_in_1c';
        }

        return $modelEvent === 'price_changed' ? 'price_changed' : 'synced_from_1c';
    }
}
