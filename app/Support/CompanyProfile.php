<?php

namespace App\Support;

use App\Enums\Locale;
use App\Models\Setting;
use App\Models\User;

/**
 * Our company's legal profile (Spec 06 7.9, Settings > Company): `company.*` settings rows,
 * legal name, address and city per document language; falls back to config('documents.seller')
 * until the profile is filled in.
 */
final class CompanyProfile
{
    /** Fields stored once per document language. */
    public const LOCALIZED = ['legal_name', 'address', 'city'];

    /** Fields stored once. */
    public const PLAIN = ['tax_id', 'vat_code', 'oked', 'phone', 'email', 'director', 'chief_accountant'];

    public static function get(string $field, ?Locale $locale = null): string
    {
        if (in_array($field, self::LOCALIZED, true)) {
            $locale ??= Locale::default();

            foreach ([$locale, Locale::default(), ...Locale::cases()] as $candidate) {
                $value = Setting::get('company.'.$field.'.'.$candidate->value);

                if (filled($value)) {
                    return (string) $value;
                }
            }

            return match ($field) {
                'legal_name' => (string) config('documents.seller.name'),
                'address' => (string) config('documents.seller.address'),
                default => '',
            };
        }

        $value = Setting::get('company.'.$field);

        if (filled($value)) {
            return (string) $value;
        }

        return match ($field) {
            'tax_id' => (string) config('documents.seller.tax_id'),
            'phone' => (string) config('documents.seller.phone'),
            default => '',
        };
    }

    /**
     * Seller block used by printed documents.
     *
     * @return array{name: string, address: string, tax_id: string, phone: string, vat_code: string, oked: string, email: string}
     */
    public static function seller(Locale $locale): array
    {
        return [
            'name' => self::get('legal_name', $locale),
            'address' => self::get('address', $locale),
            'tax_id' => self::get('tax_id'),
            'phone' => self::get('phone'),
            'vat_code' => self::get('vat_code'),
            'oked' => self::get('oked'),
            'email' => self::get('email'),
        ];
    }

    /**
     * Raw stored values for the settings form (localized fields as "field.locale").
     *
     * @return array<string, string>
     */
    public static function formValues(): array
    {
        $values = [];

        foreach (self::LOCALIZED as $field) {
            foreach (Locale::cases() as $locale) {
                $values[$field.'.'.$locale->value] = (string) Setting::get('company.'.$field.'.'.$locale->value, '');
            }
        }

        foreach (self::PLAIN as $field) {
            $values[$field] = (string) Setting::get('company.'.$field, '');
        }

        return $values;
    }

    /**
     * @param  array<string, string|null>  $values  keys as in formValues()
     */
    public static function save(array $values, ?User $by = null): void
    {
        $rows = [];

        foreach (self::formValues() as $key => $unused) {
            $value = trim((string) ($values[$key] ?? ''));
            $rows['company.'.$key] = $value === '' ? null : $value;
        }

        Setting::putMany($rows, $by);
    }
}
