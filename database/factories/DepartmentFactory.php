<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'code' => fake()->unique()->lexify('dept-????????'),
            'name' => ['uz' => ucfirst($name), 'ru' => ucfirst($name), 'en' => ucfirst($name)],
            'parent_id' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(100, 900),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
