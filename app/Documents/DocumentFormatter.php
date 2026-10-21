<?php

namespace App\Documents;

use App\Enums\Locale;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Locale-aware formatting for printed documents: dates, quantities, percentages, money
 * and amounts in words. Amounts are integers or decimal strings, never floats, and
 * rounding is half away from zero on the decimal string (bcmath), so no float drift.
 */
final class DocumentFormatter
{
    public function __construct(public readonly Locale $locale) {}

    public function date(DateTimeInterface|string|null $date): string
    {
        return $date === null ? '' : $this->carbon($date)->translatedFormat($this->locale->dateFormat());
    }

    public function dateTime(DateTimeInterface|string|null $date): string
    {
        return $date === null ? '' : $this->carbon($date)->translatedFormat($this->locale->dateTimeFormat());
    }

    /** Month-name date, e.g. "2026-yil 16-oktabr", "16 октября 2026 г.", "October 16, 2026". */
    public function longDate(DateTimeInterface|string|null $date): string
    {
        if ($date === null) {
            return '';
        }

        $formatted = $this->carbon($date)->isoFormat($this->locale->longDateFormat());

        // Carbon capitalises Uzbek month names; in running text they are lowercase.
        return $this->locale === Locale::Uz ? mb_strtolower($formatted) : $formatted;
    }

    public function number(int|string $value, int $decimals = 2): string
    {
        $rounded = self::round(self::decimal($value), $decimals);
        $negative = str_starts_with($rounded, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($rounded, '-')), 2, '');

        $grouped = strrev(implode(strrev($this->locale->thousandsSeparator()), str_split(strrev($integer), 3)));
        $formatted = $fraction === '' ? $grouped : $grouped.$this->locale->decimalSeparator().$fraction;

        return ($negative && trim($rounded, '-0.') !== '' ? '-' : '').$formatted;
    }

    /** Quantity with up to $maxDecimals decimals and no trailing zeros (10, 2,5, 0,125). */
    public function quantity(int|string $value, int $maxDecimals = 3): string
    {
        return $this->trimZeros($this->number($value, $maxDecimals));
    }

    public function percent(int|string $value, int $maxDecimals = 2): string
    {
        $number = $this->trimZeros($this->number($value, $maxDecimals));

        return $this->locale === Locale::En ? $number.'%' : $number."\u{00A0}%";
    }

    /** E.g. "1 234 567,50 UZS" (uz/ru) or "1,234,567.50 UZS" (en). */
    public function money(int|string $amount, string $currency, int $decimals = 2): string
    {
        return $this->number($amount, $decimals)."\u{00A0}".strtoupper($currency);
    }

    public function amountInWords(int|string $amount, string $currency): string
    {
        return AmountInWords::money(self::decimal($amount), $currency, $this->locale);
    }

    /**
     * Validate and normalise an integer or decimal string ("1234.5", "-0.75").
     */
    public static function decimal(int|string $value): string
    {
        $value = is_int($value) ? (string) $value : trim($value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal number: [{$value}].");
        }

        return $value;
    }

    /**
     * Round a decimal string half away from zero to $decimals places.
     */
    public static function round(string $value, int $decimals): string
    {
        $half = '0.'.str_repeat('0', $decimals).'5';
        $adjusted = str_starts_with($value, '-') ? bcsub($value, $half, $decimals + 1) : bcadd($value, $half, $decimals + 1);

        // bcadd with a scale truncates, so adding half a unit then truncating rounds half away from zero.
        return bcadd($adjusted, '0', $decimals);
    }

    private function trimZeros(string $number): string
    {
        $separator = $this->locale->decimalSeparator();

        return str_contains($number, $separator) ? rtrim(rtrim($number, '0'), $separator) : $number;
    }

    private function carbon(DateTimeInterface|string $date): Carbon
    {
        return Carbon::parse($date)->locale($this->locale->carbonLocale());
    }
}
