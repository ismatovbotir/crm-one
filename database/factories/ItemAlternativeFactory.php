<?php

namespace Database\Factories;

use App\Enums\ItemAlternativeRelation;
use App\Models\Item;
use App\Models\ItemAlternative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemAlternative>
 */
class ItemAlternativeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'alternative_item_id' => Item::factory(),
            'relation' => ItemAlternativeRelation::Analogue,
            'is_mutual' => true,
            'source' => 'crm',
            'status' => 'approved',
        ];
    }
}
