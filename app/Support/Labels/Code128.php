<?php

namespace App\Support\Labels;

use InvalidArgumentException;

/**
 * Code 128 encoder (subsets B and C): returns the bar/space module string ('1' = bar).
 * Runs of four or more digits are packed in subset C, everything else in subset B.
 */
final class Code128
{
    private const START_B = 104;

    private const START_C = 105;

    private const CODE_B = 100;

    private const CODE_C = 99;

    private const STOP = 106;

    /** Bar/space widths per symbol value (0..106); the stop symbol has 7 elements. */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /**
     * @return list<int> symbol values including start, checksum and stop
     */
    public static function symbols(string $text): array
    {
        if (! self::canEncode($text)) {
            throw new InvalidArgumentException('Code 128 text must be printable ASCII and not empty.');
        }

        $symbols = [];
        $subset = null;
        $length = strlen($text);
        $i = 0;

        while ($i < $length) {
            $digitRun = strspn($text, '0123456789', $i);
            $useC = $digitRun >= 4 || ($digitRun >= 2 && $digitRun === $length && $i === 0);

            if ($useC) {
                if ($subset !== 'C') {
                    $symbols[] = $subset === null ? self::START_C : self::CODE_C;
                    $subset = 'C';
                }

                for ($pairs = intdiv($digitRun, 2); $pairs > 0; $pairs--) {
                    $symbols[] = (int) substr($text, $i, 2);
                    $i += 2;
                }

                continue;
            }

            if ($subset !== 'B') {
                $symbols[] = $subset === null ? self::START_B : self::CODE_B;
                $subset = 'B';
            }

            $symbols[] = ord($text[$i]) - 32;
            $i++;
        }

        $checksum = $symbols[0];

        foreach (array_slice($symbols, 1) as $position => $value) {
            $checksum += $value * ($position + 1);
        }

        $symbols[] = $checksum % 103;
        $symbols[] = self::STOP;

        return $symbols;
    }

    /**
     * Module string, e.g. "11010010000...": 1 = bar, 0 = space.
     */
    public static function modules(string $text): string
    {
        $modules = '';

        foreach (self::symbols($text) as $symbol) {
            $isBar = true;

            foreach (str_split(self::PATTERNS[$symbol]) as $width) {
                $modules .= str_repeat($isBar ? '1' : '0', (int) $width);
                $isBar = ! $isBar;
            }
        }

        return $modules;
    }

    public static function canEncode(string $text): bool
    {
        return $text !== '' && preg_match('/^[\x20-\x7E]+$/', $text) === 1;
    }
}
