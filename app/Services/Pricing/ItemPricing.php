<?php

namespace App\Services\Pricing;

use App\Documents\DocumentFormatter;
use App\Models\Item;
use App\Services\Pricing\Exceptions\ItemNotSellable;
use App\Services\Pricing\Exceptions\PriceNotFound;
use App\Support\Money;

/**
 * The single price lookup (Spec 01/02 rev. 4, replaces the price-list PriceResolver): every item
 * has one sale (out) price and one purchase (in) price from 1C. No customer, quantity or dated
 * prices, no FX conversion. Costs (stock cost price, purchase price) are treated as net of VAT.
 */
class ItemPricing
{
    public const SOURCE_ITEM = 'item_sale_price';

    public const SOURCE_MANUAL = 'manual';

    /**
     * The item's sale price in $currency.
     *
     * @throws ItemNotSellable when the item is inactive, archived or deleted in 1C
     * @throws PriceNotFound when 1C sent no sale price or it is in another currency
     */
    public function salePrice(Item $item, string $currency): Money
    {
        if (! $item->isSellable()) {
            throw new ItemNotSellable("Item [{$item->sku}] is not sellable.");
        }

        $price = $item->salePrice();

        if ($price === null || $price->currency !== strtoupper($currency)) {
            throw new PriceNotFound("No {$currency} sale price for item [{$item->sku}].");
        }

        return $price;
    }

    /**
     * Converts a unit price between net and gross bases once, rounded half-up (Spec 05 BR-2).
     */
    public static function toBasis(int $price, bool $fromGross, bool $toGross, int $taxRateBp): int
    {
        if ($fromGross === $toGross || $taxRateBp === 0) {
            return $price;
        }

        $converted = $toGross
            ? bcdiv(bcmul((string) $price, (string) (10000 + $taxRateBp), 0), '10000', 6)
            : bcdiv(bcmul((string) $price, '10000', 0), (string) (10000 + $taxRateBp), 6);

        return (int) DocumentFormatter::round($converted, 0);
    }

    /**
     * Net cost per unit for margins and the below-cost check (stock cost price, else purchase
     * price), or null when unknown in that currency.
     */
    public function costBasis(Item $item, string $currency): ?int
    {
        $item->loadMissing('stocks');

        return $item->costBasis($currency)?->amount;
    }
}
