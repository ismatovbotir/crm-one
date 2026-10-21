<?php

namespace App\Services\Stock;

use App\Support\BusinessSettings;

/**
 * BR-SN.1 normalization, one implementation for typing, scanning, paste and import: strip
 * invisible characters, trim, remove (or collapse) internal whitespace, uppercase, check the
 * allowed pattern and length. Cyrillic letters are rejected with a layout hint (scanners on a
 * Cyrillic layout type look-alike letters); no automatic homoglyph mapping.
 */
class SerialNormalizer
{
    /**
     * @return array{value: string|null, raw: string, error: string|null}
     */
    public function normalize(string $input): array
    {
        $raw = mb_substr($this->stripInvisible($input), 0, 100);
        $value = trim($raw);

        $value = BusinessSettings::bool('serials.remove_internal_whitespace')
            ? (string) preg_replace('/\s+/u', '', $value)
            : (string) preg_replace('/\s+/u', ' ', $value);

        $value = mb_strtoupper($value);

        if ($value === '') {
            return ['value' => null, 'raw' => $raw, 'error' => 'empty'];
        }

        if (preg_match('/\p{Cyrillic}/u', $value) === 1) {
            return ['value' => null, 'raw' => $raw, 'error' => 'cyrillic'];
        }

        if (preg_match('/^\d+(\.\d+)?E[+-]?\d+$/', $value) === 1) {
            return ['value' => null, 'raw' => $raw, 'error' => 'scientific_notation'];
        }

        if (str_starts_with($value, '=')) {
            return ['value' => null, 'raw' => $raw, 'error' => 'formula'];
        }

        $min = BusinessSettings::int('serials.min_length');
        $max = BusinessSettings::int('serials.max_length');

        if (mb_strlen($value) < $min) {
            return ['value' => null, 'raw' => $raw, 'error' => 'too_short'];
        }

        if (mb_strlen($value) > $max) {
            return ['value' => null, 'raw' => $raw, 'error' => 'too_long'];
        }

        $pattern = (string) (config('serials.allowed_pattern') ?: '/^[A-Z0-9][A-Z0-9\-\/._]*$/');

        if (preg_match($pattern, $value) !== 1) {
            return ['value' => null, 'raw' => $raw, 'error' => 'invalid_chars'];
        }

        return ['value' => $value, 'raw' => $raw, 'error' => null];
    }

    /**
     * Splits pasted text into candidate serials: one per line; tab, semicolon and comma also split.
     *
     * @return list<string>
     */
    public function split(string $text): array
    {
        $parts = preg_split('/[\r\n\t;,]+/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
    }

    protected function stripInvisible(string $input): string
    {
        $input = str_replace("\u{00A0}", ' ', $input);

        return (string) preg_replace('/[\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\p{Cc}]/u', '', $input);
    }
}
