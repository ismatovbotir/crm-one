<?php

namespace App\Enums;

/**
 * Supported UI and document languages. The backing value is the Laravel locale
 * (lang/{value}/*.php). Uzbek is Latin script and the application default.
 */
enum Locale: string
{
    case Uz = 'uz';
    case Ru = 'ru';
    case En = 'en';

    /** Session key holding a guest's (or not yet persisted) choice. */
    public const SESSION_KEY = 'locale';

    /** Non-breaking space used as the thousands separator in uz/ru. */
    private const NBSP = "\u{00A0}";

    /**
     * Native name, intentionally not translated: a user must recognise their own language.
     */
    public function label(): string
    {
        return match ($this) {
            self::Uz => 'O‘zbekcha',
            self::Ru => 'Русский',
            self::En => 'English',
        };
    }

    public function shortCode(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Carbon locale. Carbon's plain "uz" is Cyrillic, so Latin must be explicit.
     */
    public function carbonLocale(): string
    {
        return match ($this) {
            self::Uz => 'uz_Latn',
            self::Ru => 'ru',
            self::En => 'en',
        };
    }

    /** ICU / BCP 47 locale for Intl-based formatting and the <html lang> attribute. */
    public function intlLocale(): string
    {
        return match ($this) {
            self::Uz => 'uz-Latn-UZ',
            self::Ru => 'ru-RU',
            self::En => 'en-US',
        };
    }

    /** Short numeric date, PHP format. */
    public function dateFormat(): string
    {
        return match ($this) {
            self::Uz, self::Ru => 'd.m.Y',
            self::En => 'd M Y',
        };
    }

    public function dateTimeFormat(): string
    {
        return $this->dateFormat().' H:i';
    }

    /**
     * Long, month-name date as a Carbon isoFormat pattern:
     * "2026-yil 16-oktabr", "16 октября 2026 г.", "October 16, 2026".
     */
    public function longDateFormat(): string
    {
        return match ($this) {
            self::Uz => 'YYYY-[yil] D-MMMM',
            self::Ru, self::En => 'LL',
        };
    }

    public function decimalSeparator(): string
    {
        return match ($this) {
            self::Uz, self::Ru => ',',
            self::En => '.',
        };
    }

    public function thousandsSeparator(): string
    {
        return match ($this) {
            self::Uz, self::Ru => self::NBSP,
            self::En => ',',
        };
    }

    public static function default(): self
    {
        return self::tryFrom((string) config('app.locale')) ?? self::Uz;
    }

    /**
     * Configured locales, in display order. Unknown entries in config are ignored.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        $configured = (array) config('app.available_locales', array_column(self::cases(), 'value'));

        return array_values(array_filter(array_map(
            fn (mixed $value): ?self => is_string($value) ? self::tryFrom($value) : null,
            $configured,
        )));
    }

    public static function isAvailable(mixed $value): bool
    {
        return is_string($value) && in_array(self::tryFrom($value), self::available(), true);
    }

    /**
     * The supported locale for a value, or null when it is not supported.
     */
    public static function fromAvailable(mixed $value): ?self
    {
        return self::isAvailable($value) ? self::from($value) : null;
    }

    public static function current(): self
    {
        return self::tryFrom(app()->getLocale()) ?? self::default();
    }
}
