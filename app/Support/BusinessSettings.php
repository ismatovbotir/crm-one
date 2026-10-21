<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Business rule switches: a `settings` row with the same key wins over the config default
 * (e.g. "orders.agreement_required" overrides config('orders.agreement_required')).
 */
final class BusinessSettings
{
    public static function bool(string $key): bool
    {
        $stored = Setting::get($key);

        return $stored === null ? (bool) config($key) : filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    public static function string(string $key): string
    {
        $stored = Setting::get($key);

        return $stored === null || $stored === '' ? (string) config($key) : (string) $stored;
    }

    public static function int(string $key): int
    {
        $stored = Setting::get($key);

        return $stored === null || ! is_numeric($stored) ? (int) config($key) : (int) $stored;
    }

    /**
     * @return array<mixed>
     */
    public static function array(string $key): array
    {
        $stored = Setting::get($key);
        $decoded = $stored === null ? null : json_decode($stored, true);

        return is_array($decoded) ? $decoded : (array) config($key, []);
    }
}
