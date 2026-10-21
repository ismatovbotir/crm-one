<?php

namespace App\Models;

use Database\Factories\ItemStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current stock balance of an item in a warehouse, as last reported by 1C (read-only, Spec 02 BR-I.4),
 * with the total stock cost of that quantity (`cost_minor` in `cost_currency`, rev. 4).
 *
 * @property string $quantity
 * @property string $reserved
 */
#[Fillable(['item_id', 'warehouse_id', 'quantity', 'reserved', 'cost_minor', 'cost_currency', 'as_of', 'received_at'])]
class ItemStock extends Model
{
    /** @use HasFactory<ItemStockFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'reserved' => 'decimal:3',
            'cost_minor' => 'integer',
            'as_of' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * quantity - reserved, floored at 0 (negative 1C balances show as 0 with a warning).
     */
    public function available(): string
    {
        $available = bcsub((string) $this->quantity, (string) $this->reserved, 3);

        return bccomp($available, '0', 3) < 0 ? '0.000' : $available;
    }

    public function isNegative(): bool
    {
        return bccomp((string) $this->quantity, '0', 3) < 0;
    }
}
