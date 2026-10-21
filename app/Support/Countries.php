<?php

namespace App\Support;

use Locale as IntlLocale;

/**
 * ISO 3166-1 countries for the partner form (Spec 03 section 24.7): names in the UI language
 * (PHP intl), Uzbekistan first, then its neighbours, then frequent partner countries, then the rest
 * alphabetically.
 */
final class Countries
{
    public const PINNED = ['UZ', 'KZ', 'KG', 'TJ', 'TM', 'AF', 'RU', 'CN', 'TR', 'KR', 'DE', 'AE'];

    public const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ',
        'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
        'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU', 'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT', 'JE', 'JM', 'JO', 'JP',
        'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
        'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
        'UA', 'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    public static function name(string $code, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = class_exists(IntlLocale::class) ? IntlLocale::getDisplayRegion('-'.$code, $locale) : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }

    /**
     * Pinned countries first, the rest sorted by name in the UI language; optionally filtered.
     *
     * @return array<string, string> code => name
     */
    public static function options(string $search = '', ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $all = [];

        foreach (self::CODES as $code) {
            $all[$code] = self::name($code, $locale);
        }

        $rest = array_diff_key($all, array_flip(self::PINNED));
        uasort($rest, fn (string $a, string $b): int => strcoll($a, $b) ?: strcmp($a, $b));
        $options = [...array_intersect_key(array_replace(array_flip(self::PINNED), $all), array_flip(self::PINNED)), ...$rest];
        $needle = mb_strtolower(trim($search));

        return $needle === '' ? $options : array_filter($options, fn (string $name, string $code): bool => str_contains(mb_strtolower($name), $needle) || mb_strtolower($code) === $needle, ARRAY_FILTER_USE_BOTH);
    }

    public static function isValid(string $code): bool
    {
        return in_array(strtoupper($code), self::CODES, true);
    }
}
