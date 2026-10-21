<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'sort_order' => 0,
            'is_active' => true,
            'external_id' => (string) Str::uuid(),
            'synced_at' => now(),
        ];
    }

    /**
     * A category created in the CRM (no 1C GUID); subject to Category::MAX_DEPTH.
     */
    public function crmCreated(): static
    {
        return $this->state(fn (array $attributes) => ['external_id' => null, 'synced_at' => null]);
    }

    public function childOf(Category $parent): static
    {
        return $this->state(fn (array $attributes) => ['parent_id' => $parent->id]);
    }
}
