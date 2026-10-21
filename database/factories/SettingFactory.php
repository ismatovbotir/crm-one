<?php

namespace Database\Factories;

use App\Enums\ThemeColor;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->word(),
            'updated_by' => null,
        ];
    }

    public function themeColor(ThemeColor $color, string $hex): static
    {
        return $this->state(fn (): array => [
            'key' => $color->settingKey(),
            'value' => $hex,
        ]);
    }
}
