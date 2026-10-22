<?php

namespace App\Support\Labels;

use InvalidArgumentException;

/**
 * EAN-13 encoder: 12 or 13 digits (the check digit is computed for 12, verified for 13).
 */
final class Ean13
{
    private const L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    private const PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    public static function canEncode(string $digits): bool
    {
        if (preg_match('/^\d{12,13}$/', $digits) !== 1) {
            return false;
        }

        return strlen($digits) === 12 || self::checkDigit(substr($digits, 0, 12)) === (int) $digits[12];
    }

    public static function checkDigit(string $twelve): int
    {
        $sum = 0;

        foreach (str_split($twelve) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * The full 13 digits (check digit appended when only 12 were given).
     */
    public static function normalize(string $digits): string
    {
        if (! self::canEncode($digits)) {
            throw new InvalidArgumentException('Not a valid EAN-13 value.');
        }

        return strlen($digits) === 12 ? $digits.self::checkDigit($digits) : $digits;
    }

    /**
     * Module string for the 95 modules, 1 = bar.
     */
    public static function modules(string $digits): string
    {
        $digits = self::normalize($digits);
        $parity = self::PARITY[(int) $digits[0]];
        $modules = '101';

        for ($i = 1; $i <= 6; $i++) {
            $modules .= ($parity[$i - 1] === 'L' ? self::L : self::G)[(int) $digits[$i]];
        }

        $modules .= '01010';

        for ($i = 7; $i <= 12; $i++) {
            $modules .= self::R[(int) $digits[$i]];
        }

        return $modules.'101';
    }
}
