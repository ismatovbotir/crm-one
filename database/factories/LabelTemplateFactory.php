<?php

namespace Database\Factories;

use App\Models\LabelTemplate;
use App\Support\Labels\LabelLayout;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabelTemplate>
 */
class LabelTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'width_mm' => 58,
            'height_mm' => 40,
            'elements' => LabelLayout::starter(58, 40),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
