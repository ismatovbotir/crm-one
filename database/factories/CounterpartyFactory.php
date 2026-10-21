<?php

namespace Database\Factories;

use App\Enums\CounterpartyStatus;
use App\Models\Counterparty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Counterparty>
 */
class CounterpartyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'country' => 'UZ',
            'city' => 'Tashkent',
            'email' => fake()->unique()->companyEmail(),
            'phone' => '+998'.fake()->numerify('#########'),
            'is_supplier' => true,
            'is_carrier' => false,
            'status' => CounterpartyStatus::Qualified,
        ];
    }

    public function carrier(): static
    {
        return $this->state(fn (): array => ['is_supplier' => false, 'is_carrier' => true]);
    }

    public function supplier(): static
    {
        return $this->state(fn (): array => ['is_supplier' => true, 'is_carrier' => false]);
    }

    public function status(CounterpartyStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
