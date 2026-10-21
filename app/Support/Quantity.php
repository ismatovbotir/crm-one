<?php

namespace App\Support;

/**
 * Quantity helpers (Spec 01 BR-3): quantities are decimal strings with at most 3 decimals,
 * never negative, and never more decimals than the unit's precision.
 */
final class Quantity
{
    public const SCALE = 3;

    /**
     * Trim and accept a decimal comma: " 1,5 " => "1.5". Returns the input unchanged when it is not numeric.
     */
    public static function normalize(string $value): string
    {
        return str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));
    }

    /**
     * Stored decimal without trailing zeros for messages: "10.000" => "10", "2.500" => "2.5".
     */
    public static function trimmed(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public static function isDecimal(string $value): bool
    {
        return preg_match('/^\d+(\.\d+)?$/', $value) === 1;
    }

    /**
     * Decimal places that matter (trailing zeros ignored): "1.500" => 1.
     */
    public static function decimals(string $value): int
    {
        $fraction = explode('.', $value)[1] ?? '';

        return strlen(rtrim($fraction, '0'));
    }

    /**
     * Non-negative decimal with no more significant decimals than the precision allows (0..3).
     */
    public static function fitsPrecision(string $value, int $precision): bool
    {
        $value = self::normalize($value);

        return self::isDecimal($value) && self::decimals($value) <= min($precision, self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /**
     * Whether $quantity is an exact multiple of $step (step > 0).
     */
    public static function isMultipleOf(string $quantity, string $step): bool
    {
        if (bccomp($step, '0', self::SCALE) <= 0) {
            return true;
        }

        return bccomp(bcmod($quantity, $step, self::SCALE), '0', self::SCALE) === 0;
    }

    /**
     * Canonical storage form with 3 decimals: "1.5" => "1.500".
     */
    public static function toStorage(string $value): string
    {
        return bcadd(self::normalize($value), '0', self::SCALE);
    }
}
