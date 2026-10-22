<?php

namespace App\Support\Labels;

use App\Models\Item;
use App\Models\ItemSerial;
use App\Support\Money;

/**
 * Values printed on one label. "price" is a whole amount (no coins, rounded half-up) split into its
 * thousand groups, so the template can set the last three digits and the currency in a smaller font.
 *
 * @phpstan-type PriceParts array{groups: list<string>, currency: string}
 * @phpstan-type LabelValues array{fields: array<string, string>, price: PriceParts|null}
 */
final class LabelData
{
    /**
     * @return LabelValues
     */
    public static function forItem(Item $item, ?ItemSerial $serial = null): array
    {
        $item->loadMissing(['category', 'unit']);

        return [
            'fields' => [
                'name' => $item->name,
                'sku' => $item->sku,
                'article' => (string) $item->article,
                'barcode' => self::barcodeValue($item),
                'serial' => (string) $serial?->serial_raw,
                'category' => (string) $item->category?->name,
                'unit' => (string) $item->unit?->name,
                'date' => now()->format('d.m.Y'),
                'custom' => '',
            ],
            'price' => self::priceParts($item->salePrice()),
        ];
    }

    /**
     * Fixed sample values for the designer preview.
     *
     * @return LabelValues
     */
    public static function sample(): array
    {
        return [
            'fields' => [
                'name' => 'Sample item name 500 ml',
                'sku' => '00-00001234',
                'article' => 'ART-1234',
                'barcode' => '5901234123457',
                'serial' => 'SN-0001234',
                'category' => 'Category',
                'unit' => 'pcs',
                'date' => now()->format('d.m.Y'),
                'custom' => '',
            ],
            'price' => self::priceParts(Money::of(125000050, 'UZS')),
        ];
    }

    /**
     * The code encoded in the barcode: the item's own barcode, else its SKU.
     */
    public static function barcodeValue(Item $item): string
    {
        return filled($item->barcode) ? (string) $item->barcode : (string) $item->sku;
    }

    /**
     * @return PriceParts|null
     */
    public static function priceParts(?Money $price): ?array
    {
        if ($price === null) {
            return null;
        }

        $divisor = 10 ** Money::exponent($price->currency);
        $whole = (string) intdiv($price->amount + intdiv($divisor, 2), $divisor);
        $groups = array_reverse(array_map('strrev', str_split(strrev($whole), 3)));

        return ['groups' => $groups, 'currency' => $price->currency];
    }
}
