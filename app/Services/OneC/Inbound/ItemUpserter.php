<?php

namespace App\Services\OneC\Inbound;

use App\Enums\ItemType;
use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use App\Support\ItemCodes;
use App\Support\Money;

/**
 * Items from 1C (BR-I.13): `sku` = 1C code (non-unique), unit by GUID (required), group by GUID
 * (optional), VAT "12" => 1200 bp, "exempt" => 0 + is_vat_exempt. `min_order_qty` and `order_step`
 * are CRM-local and never touched.
 */
class ItemUpserter extends CatalogUpserter
{
    /** @var array<string, int|null> */
    protected array $unitIds = [];

    /** @var array<string, int|null> */
    protected array $categoryIds = [];

    protected function modelClass(): string
    {
        return Item::class;
    }

    protected function section(): string
    {
        return 'items';
    }

    protected function map(array $record, int $index, ProcessingResult $result): ?array
    {
        $guid = strtolower((string) $record['id']);
        $unitGuid = strtolower((string) $record['unit_id']);
        $unitId = $this->unitIds[$unitGuid] ??= Unit::query()->where('external_id', $unitGuid)->value('id');

        if ($unitId === null) {
            $result->fail('items', $index, $guid, 'unknown_reference', "unit {$unitGuid} not found");

            return null;
        }

        $categoryId = null;

        if (isset($record['group_id'])) {
            $groupGuid = strtolower((string) $record['group_id']);
            $categoryId = $this->categoryIds[$groupGuid] ??= Category::query()->where('external_id', $groupGuid)->value('id');

            if ($categoryId === null) {
                $result->warn('items', $index, $guid, 'unknown_reference', "group {$groupGuid} not found; item left without category");
            }
        }

        $exempt = $record['vat_rate'] === 'exempt';

        return [
            ...$this->sharedFields($record),
            ...$this->prices($record, $index, $guid, $result),
            'sku' => (string) $record['code'],
            'article' => isset($record['article']) && $record['article'] !== '' ? (string) $record['article'] : null,
            'name' => (string) $record['name'],
            'description' => $record['description'] ?? null,
            'type' => ItemType::from((string) $record['type'])->value,
            'category_id' => $categoryId,
            'unit_id' => $unitId,
            'tax_rate_bp' => $exempt ? 0 : (int) bcmul((string) $record['vat_rate'], '100', 0),
            'is_vat_exempt' => $exempt,
        ];
    }

    /**
     * The item's single sale (out) and purchase (in) price (rev. 4). 1C is the master: an absent
     * key leaves the stored price unchanged, an explicit null clears it. Currency defaults to the
     * CRM base currency; more decimals than the currency allows are rounded half-up with a warning.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function prices(array $record, int $index, string $guid, ProcessingResult $result): array
    {
        $fields = [];

        foreach (['sale', 'purchase'] as $kind) {
            if (! array_key_exists($kind.'_price', $record)) {
                continue;
            }

            if ($record[$kind.'_price'] === null) {
                $fields[$kind.'_price_minor'] = null;
                $fields[$kind.'_currency'] = null;

                continue;
            }

            $currency = strtoupper((string) ($record[$kind.'_currency'] ?? config('crm.base_currency')));
            [$money, $rounded] = Money::fromMajorRoundedHalfUp((string) $record[$kind.'_price'], $currency);

            if ($rounded) {
                $result->warn('items', $index, $guid, 'price_rounded', "{$kind}_price rounded to {$money->toMajor()}");
            }

            $fields[$kind.'_price_minor'] = $money->amount;
            $fields[$kind.'_currency'] = $currency;
        }

        if (is_bool($record['prices_include_vat'] ?? null)) {
            $fields['prices_include_vat'] = $record['prices_include_vat'];
        }

        return $fields;
    }

    /**
     * Fields shared with the CRM item form (BR-I.13a / BR-I.13b): written only when 1C sends a
     * non-empty value (codes) or an explicit boolean (is_serialized). Absent, null or "" never
     * wipes what is stored, whether it came from 1C or was entered in the CRM.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function sharedFields(array $record): array
    {
        $fields = [];

        foreach (['accountant_code', 'class_code', 'package_code'] as $field) {
            $value = ItemCodes::clean($record[$field] ?? null);

            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        if (is_bool($record['is_serialized'] ?? null)) {
            // TODO Spec 06: once the CRM serial registry exists, refuse to clear the flag while serials are on stock.
            $fields['is_serialized'] = $record['is_serialized'];
        }

        return $fields;
    }
}
