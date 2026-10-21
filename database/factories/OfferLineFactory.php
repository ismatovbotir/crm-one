<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Services\Pricing\ResolvedPrice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Raw offer line with a minimal snapshot. Prefer AddOfferLine in feature tests.
 *
 * @extends Factory<OfferLine>
 */
class OfferLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'stable_key' => (string) Str::uuid(),
            'position' => 1,
            'item_id' => Item::factory(),
            'sku' => fake()->numerify('00-0000####'),
            'name' => ucfirst(fake()->words(3, true)),
            'unit_name' => 'pcs',
            'unit_precision' => 0,
            'tax_rate_bp' => 1200,
            'price_source' => ResolvedPrice::SOURCE_DEFAULT_PRICE_LIST,
            'quantity' => '1.000',
            'unit_price_minor' => 100000,
            'list_unit_price_minor' => 100000,
            'discount_bp' => 0,
        ];
    }
}
