<?php

namespace App\Documents;

use App\Enums\Locale;
use Illuminate\Support\Str;

/**
 * Spells amounts in words for printed documents ("Bir million ikki yuz ming so‘m 50 tiyin").
 * Integers up to 999 trillion. Currency names and the phrase pattern live in
 * lang/{locale}/documents.php (currencies.*, amount_in_words) so wording stays translatable.
 */
final class AmountInWords
{
    /**
     * @param  string  $amount  decimal string, e.g. "1200000.50"
     */
    public static function money(string $amount, string $currency, Locale $locale): string
    {
        $rounded = DocumentFormatter::round(DocumentFormatter::decimal($amount), 2);
        $negative = str_starts_with($rounded, '-');
        [$major, $minor] = explode('.', ltrim($rounded, '-'));
        $majorValue = (int) $major;
        $minorValue = (int) $minor;

        $currency = strtoupper($currency);
        $key = 'documents.currencies.'.$currency;
        $known = trans()->has($key.'.major', $locale->value, false);

        $words = self::spell($majorValue, $locale);

        $text = __('documents.amount_in_words', [
            'major' => ($negative ? self::minus($locale).' ' : '').$words,
            'major_currency' => $known ? trans_choice($key.'.major', $majorValue, [], $locale->value) : $currency,
            'minor' => $known ? $minor : $minor.'/100',
            'minor_currency' => $known ? trans_choice($key.'.minor', $minorValue, [], $locale->value) : '',
        ], $locale->value);

        return Str::ucfirst(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    public static function spell(int $number, Locale $locale): string
    {
        if ($number < 0) {
            return self::minus($locale).' '.self::spell(-$number, $locale);
        }

        if ($number === 0) {
            return match ($locale) {
                Locale::Uz => 'nol',
                Locale::Ru => 'ноль',
                Locale::En => 'zero',
            };
        }

        $groups = array_reverse(str_split(strrev((string) $number), 3));
        $groups = array_map(fn (string $group): int => (int) strrev($group), $groups);
        $scaleCount = count($groups);
        $words = [];

        foreach ($groups as $index => $group) {
            if ($group === 0) {
                continue;
            }

            $scale = $scaleCount - $index - 1;
            $words[] = match ($locale) {
                Locale::Uz => self::uzGroup($group, $scale),
                Locale::Ru => self::ruGroup($group, $scale),
                Locale::En => self::enGroup($group, $scale),
            };
        }

        return implode(' ', $words);
    }

    private static function minus(Locale $locale): string
    {
        return $locale === Locale::Ru ? 'минус' : 'minus';
    }

    private static function uzGroup(int $group, int $scale): string
    {
        $units = ['', 'bir', 'ikki', 'uch', 'to‘rt', 'besh', 'olti', 'yetti', 'sakkiz', 'to‘qqiz'];
        $tens = ['', 'o‘n', 'yigirma', 'o‘ttiz', 'qirq', 'ellik', 'oltmish', 'yetmish', 'sakson', 'to‘qson'];
        $scales = ['', 'ming', 'million', 'milliard', 'trillion'];

        $words = [];

        if (intdiv($group, 100) > 0) {
            $words[] = $units[intdiv($group, 100)].' yuz';
        }

        $words[] = $tens[intdiv($group % 100, 10)];
        $words[] = $units[$group % 10];
        $words[] = $scales[$scale];

        return implode(' ', array_filter($words));
    }

    private static function enGroup(int $group, int $scale): string
    {
        $units = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
            'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $scales = ['', 'thousand', 'million', 'billion', 'trillion'];

        $words = [];

        if (intdiv($group, 100) > 0) {
            $words[] = $units[intdiv($group, 100)].' hundred';
        }

        $rest = $group % 100;

        if ($rest > 0 && $rest < 20) {
            $words[] = $units[$rest];
        } elseif ($rest >= 20) {
            $words[] = $tens[intdiv($rest, 10)].($rest % 10 > 0 ? '-'.$units[$rest % 10] : '');
        }

        $words[] = $scales[$scale];

        return implode(' ', array_filter($words));
    }

    private static function ruGroup(int $group, int $scale): string
    {
        $hundreds = ['', 'сто', 'двести', 'триста', 'четыреста', 'пятьсот', 'шестьсот', 'семьсот', 'восемьсот', 'девятьсот'];
        $tens = ['', '', 'двадцать', 'тридцать', 'сорок', 'пятьдесят', 'шестьдесят', 'семьдесят', 'восемьдесят', 'девяносто'];
        $teens = ['десять', 'одиннадцать', 'двенадцать', 'тринадцать', 'четырнадцать', 'пятнадцать', 'шестнадцать', 'семнадцать', 'восемнадцать', 'девятнадцать'];
        // Thousands are feminine (одна тысяча, две тысячи); everything else masculine.
        $units = $scale === 1
            ? ['', 'одна', 'две', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять']
            : ['', 'один', 'два', 'три', 'четыре', 'пять', 'шесть', 'семь', 'восемь', 'девять'];
        $scales = [
            1 => ['тысяча', 'тысячи', 'тысяч'],
            2 => ['миллион', 'миллиона', 'миллионов'],
            3 => ['миллиард', 'миллиарда', 'миллиардов'],
            4 => ['триллион', 'триллиона', 'триллионов'],
        ];

        $words = [$hundreds[intdiv($group, 100)]];
        $rest = $group % 100;

        if ($rest >= 10 && $rest < 20) {
            $words[] = $teens[$rest - 10];
        } else {
            $words[] = $tens[intdiv($rest, 10)];
            $words[] = $units[$rest % 10];
        }

        if ($scale > 0) {
            $words[] = $scales[$scale][self::ruPluralIndex($group)];
        }

        return implode(' ', array_filter($words));
    }

    /**
     * 0 = one (1, 21, 101), 1 = few (2-4, 22-24), 2 = many (0, 5-20, 25-30).
     */
    private static function ruPluralIndex(int $number): int
    {
        $mod100 = $number % 100;
        $mod10 = $number % 10;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => 0,
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 1,
            default => 2,
        };
    }
}
