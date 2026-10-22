<?php

namespace App\Support\Labels;

/**
 * The element list of a label template: whitelists, defaults and the sanitiser that cleans what
 * the browser designer posts (it is client input, so every value is cast and clamped here).
 * Positions are millimetres from the label's top-left corner.
 */
final class LabelLayout
{
    public const TYPES = ['text', 'price', 'barcode', 'image', 'line', 'box'];

    /** Data fields a text or barcode element can show; "custom" shows the element's own text. */
    public const FIELDS = ['name', 'sku', 'article', 'barcode', 'serial', 'category', 'unit', 'date', 'custom'];

    public const ALIGNS = ['left', 'center', 'right'];

    public const VALIGNS = ['top', 'middle', 'bottom'];

    public const SMALL_VALIGNS = ['top', 'baseline', 'bottom'];

    public const SYMBOLOGIES = ['auto', 'code128', 'ean13'];

    public const MAX_ELEMENTS = 40;

    public const MIN_SIZE_MM = 1.0;

    /**
     * Stored layout for a new template: name, SKU, barcode and a price whose last three digits are smaller.
     *
     * @return list<array<string, mixed>>
     */
    public static function starter(float $width, float $height): array
    {
        return self::sanitize([
            ['type' => 'text', 'field' => 'name', 'x' => 2, 'y' => 2, 'w' => $width - 4, 'h' => 9, 'font_size' => 9, 'bold' => true],
            ['type' => 'price', 'x' => 2, 'y' => 12, 'w' => $width - 4, 'h' => 12, 'font_size' => 20, 'bold' => true, 'align' => 'right', 'valign' => 'bottom',
                'small_last_digits' => true, 'small_currency' => true, 'small_scale' => 55, 'small_valign' => 'top'],
            ['type' => 'barcode', 'field' => 'barcode', 'x' => 2, 'y' => 25, 'w' => $width - 4, 'h' => max(6, $height - 27), 'font_size' => 6, 'show_text' => true],
        ], $width, $height);
    }

    /**
     * @param  array<int|string, mixed>  $elements  raw posted elements
     * @return list<array<string, mixed>>
     */
    public static function sanitize(array $elements, float $width, float $height): array
    {
        $clean = [];

        foreach (array_slice(array_values($elements), 0, self::MAX_ELEMENTS) as $index => $raw) {
            if (! is_array($raw) || ! in_array($raw['type'] ?? null, self::TYPES, true)) {
                continue;
            }

            $type = $raw['type'];
            $isLine = $type === 'line';
            $w = self::clamp(self::number($raw['w'] ?? 10), $isLine ? self::MIN_SIZE_MM : self::MIN_SIZE_MM, $width);
            $h = self::clamp(self::number($raw['h'] ?? 5), self::MIN_SIZE_MM / 2, $height);
            $element = [
                'id' => self::id($raw['id'] ?? null, $index),
                'type' => $type,
                'x' => self::snap(self::clamp(self::number($raw['x'] ?? 0), 0, $width - $w)),
                'y' => self::snap(self::clamp(self::number($raw['y'] ?? 0), 0, $height - $h)),
                'w' => self::snap($w),
                'h' => self::snap($h, 0.1),
            ];

            if (in_array($type, ['text', 'price', 'barcode'], true)) {
                $element += [
                    'font_size' => self::clamp(self::number($raw['font_size'] ?? 8), 3, 120),
                    'bold' => (bool) ($raw['bold'] ?? false),
                    'italic' => (bool) ($raw['italic'] ?? false),
                    'align' => self::choice($raw['align'] ?? null, self::ALIGNS, $type === 'price' ? 'right' : 'left'),
                    'valign' => self::choice($raw['valign'] ?? null, self::VALIGNS, 'top'),
                ];
            }

            if ($type === 'text' || $type === 'barcode') {
                $element['field'] = self::choice($raw['field'] ?? null, self::FIELDS, $type === 'barcode' ? 'barcode' : 'name');
                $element['text'] = $element['field'] === 'custom' ? mb_substr(trim((string) ($raw['text'] ?? '')), 0, 200) : '';
            }

            if ($type === 'text') {
                $element['uppercase'] = (bool) ($raw['uppercase'] ?? false);
            }

            if ($type === 'price') {
                $element += [
                    'small_last_digits' => (bool) ($raw['small_last_digits'] ?? true),
                    'small_currency' => (bool) ($raw['small_currency'] ?? true),
                    'show_currency' => (bool) ($raw['show_currency'] ?? true),
                    'small_scale' => (int) self::clamp(self::number($raw['small_scale'] ?? 55), 20, 100),
                    'small_valign' => self::choice($raw['small_valign'] ?? null, self::SMALL_VALIGNS, 'top'),
                ];
            }

            if ($type === 'barcode') {
                $element['symbology'] = self::choice($raw['symbology'] ?? null, self::SYMBOLOGIES, 'auto');
                $element['show_text'] = (bool) ($raw['show_text'] ?? true);
            }

            if ($type === 'box') {
                $element['stroke'] = self::clamp(self::number($raw['stroke'] ?? 0.3), 0.1, 3);
            }

            $clean[] = $element;
        }

        return $clean;
    }

    private static function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function clamp(float $value, float $low, float $high): float
    {
        return min(max($value, $low), max($low, $high));
    }

    private static function snap(float $value, float $step = 0.5): float
    {
        return round($value / $step) * $step;
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function choice(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function id(mixed $value, int $index): string
    {
        $id = is_string($value) ? preg_replace('/[^a-z0-9_-]/i', '', $value) : '';

        return $id !== '' && $id !== null ? substr($id, 0, 24) : 'el'.($index + 1);
    }
}
