<?php

namespace App\Support;

/**
 * Uzbekistan identifiers (Spec 04 section 16): phone numbers normalised to +998XXXXXXXXX and the
 * 9-digit taxpayer number (INN / STIR) format. The official STIR check-digit algorithm is not
 * published, so only the format is checked (9 digits, not starting with 0); no guessed checksum.
 */
final class UzbekIdentifiers
{
    /**
     * +998 and 9 national digits for Uzbek numbers written locally (90 123 45 67, 0 90 …, 998 …);
     * other international numbers keep their + and digits; null when nothing usable is left.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $digits = (string) preg_replace('/\D/', '', $raw);

        if ($digits === '') {
            return null;
        }

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '998') => '+'.$digits,
            strlen($digits) === 9 => '+998'.$digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '+998'.substr($digits, 1),
            str_starts_with($raw, '+') || strlen($digits) > 9 => '+'.$digits,
            default => $digits,
        };
    }

    public static function isUzbekMobile(?string $normalized): bool
    {
        return $normalized !== null && preg_match('/^\+998\d{9}$/', $normalized) === 1;
    }

    /** Error key under leads.tax_id_errors.* or null when the format is valid. */
    public static function taxIdError(?string $taxId): ?string
    {
        $taxId = trim((string) $taxId);

        if ($taxId === '') {
            return null;
        }

        return match (true) {
            preg_match('/^\d+$/', $taxId) !== 1 => 'digits',
            strlen($taxId) !== 9 => 'length',
            str_starts_with($taxId, '0') => 'leading_zero',
            default => null,
        };
    }
}
