<?php

namespace Database\Factories;

use App\Enums\OptionGroupKind;
use App\Models\Offer;
use App\Models\OfferOptionGroup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfferOptionGroup>
 */
class OfferOptionGroupFactory extends Factory
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
            'kind' => OptionGroupKind::Alternative,
            'title' => ucfirst(fake()->words(3, true)),
            'min_select' => 1,
            'max_select' => 1,
        ];
    }

    public function optional(int $max = 1): static
    {
        return $this->state(fn (array $attributes) => ['kind' => OptionGroupKind::Optional, 'min_select' => 0, 'max_select' => $max]);
    }
}
