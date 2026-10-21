<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemStock>
 */
class ItemStockFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => (string) fake()->numberBetween(0, 500),
            'reserved' => '0',
            'as_of' => now(),
            'received_at' => now(),
        ];
    }
}
