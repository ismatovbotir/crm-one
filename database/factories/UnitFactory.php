<?php

namespace Database\Factories;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('9##'),
            'name' => fake()->unique()->lexify('u???'),
            'precision' => 0,
            'is_active' => true,
            'external_id' => (string) Str::uuid(),
            'synced_at' => now(),
        ];
    }

    public function precision(int $precision): static
    {
        return $this->state(fn (array $attributes) => ['precision' => $precision]);
    }

    public function pieces(): static
    {
        return $this->state(fn (array $attributes) => ['code' => '796', 'name' => 'pcs', 'precision' => 0]);
    }

    public function kilograms(): static
    {
        return $this->state(fn (array $attributes) => ['code' => '166', 'name' => 'kg', 'precision' => 3]);
    }
}
