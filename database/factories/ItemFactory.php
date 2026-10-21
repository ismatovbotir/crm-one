<?php

namespace Database\Factories;

use App\Enums\ItemType;
use App\Models\Category;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sku' => fake()->unique()->numerify('00-0000####'),
            'article' => null,
            'name' => ucfirst(fake()->unique()->words(3, true)),
            'description' => null,
            'type' => ItemType::Product,
            'category_id' => null,
            'unit_id' => Unit::factory(),
            'tax_rate_bp' => (int) config('crm.default_tax_rate_bp'),
            'is_vat_exempt' => false,
            'is_active' => true,
            'external_id' => (string) Str::uuid(),
            'synced_at' => now(),
        ];
    }

    /**
     * Accounting codes (Spec 02 BR-I.13a).
     */
    public function withAccountingCodes(): static
    {
        return $this->state(fn (array $attributes) => [
            'accountant_code' => fake()->bothify('41.01-####'),
            'class_code' => fake()->numerify('#################'),
            'package_code' => fake()->numerify('#######'),
        ]);
    }

    /**
     * The single sale price (rev. 4) in major units, e.g. ->priced('100.00').
     */
    public function priced(string $sale, string $currency = 'UZS', bool $includesVat = false, ?string $purchase = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sale_price_minor' => (int) bcmul($sale, '100', 0),
            'sale_currency' => $currency,
            'prices_include_vat' => $includesVat,
            'purchase_price_minor' => $purchase === null ? null : (int) bcmul($purchase, '100', 0),
            'purchase_currency' => $purchase === null ? null : $currency,
        ]);
    }

    /**
     * Serial-tracked item (Spec 02 BR-I.13b): whole quantities only.
     */
    public function serialized(): static
    {
        return $this->state(fn (array $attributes) => ['is_serialized' => true]);
    }

    public function service(): static
    {
        return $this->state(fn (array $attributes) => ['type' => ItemType::Service]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['deleted_at' => now()]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn (array $attributes) => ['category_id' => $category->id]);
    }

    public function withUnit(Unit $unit): static
    {
        return $this->state(fn (array $attributes) => ['unit_id' => $unit->id]);
    }
}
