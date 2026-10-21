<?php

namespace App\Support;

/**
 * The one place for the lenient format rules of item accounting codes (Spec 02 BR-I.13a). The
 * official formats are still to be confirmed with the accountants (D-I.23), so the checks are
 * deliberately loose: codes are strings (leading zeros kept), no classifier lookup.
 *
 *  - accountant_code: any text up to 64 characters;
 *  - class_code (IKPU / MXIK, national product/service classifier): digits only, at most 17;
 *    a length other than 17 is only a warning;
 *  - package_code (package code of the same catalog): digits only, at most 32.
 *
 * Methods return an error code (`too_long`, `invalid_format`) or null.
 */
final class ItemCodes
{
    public const ACCOUNTANT_MAX = 64;

    public const CLASS_MAX = 17;

    public const CLASS_EXPECTED_LENGTH = 17;

    public const PACKAGE_MAX = 32;

    /** Trimmed value, or null for null/empty (empty never wipes a stored value). */
    public static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function accountantCodeError(?string $value): ?string
    {
        return $value !== null && mb_strlen($value) > self::ACCOUNTANT_MAX ? 'too_long' : null;
    }

    public static function classCodeError(?string $value): ?string
    {
        return self::digitsError($value, self::CLASS_MAX);
    }

    public static function packageCodeError(?string $value): ?string
    {
        return self::digitsError($value, self::PACKAGE_MAX);
    }

    /** Non-blocking: IKPU codes are normally exactly 17 digits. */
    public static function classCodeHasUnusualLength(?string $value): bool
    {
        return $value !== null && self::classCodeError($value) === null && strlen($value) !== self::CLASS_EXPECTED_LENGTH;
    }

    private static function digitsError(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            return 'invalid_format';
        }

        return strlen($value) > $max ? 'too_long' : null;
    }
}
