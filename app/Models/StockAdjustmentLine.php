<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a stock adjustment: a new serial (found), an old → new serial on a delivery note line
 * (serial correction; `old_serial_outcome` says whether the old unit goes back to `available` or to
 * `quarantined`) or a signed quantity (quantity correction).
 *
 * @property string $quantity
 */
#[Fillable([
    'adjustment_id', 'position', 'item_id', 'quantity', 'serial_raw', 'serial_normalized', 'old_item_serial_id', 'new_item_serial_id',
    'delivery_line_id', 'old_serial_outcome', 'note',
])]
class StockAdjustmentLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<StockAdjustment, $this>
     */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'adjustment_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ItemSerial, $this>
     */
    public function oldSerial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'old_item_serial_id');
    }

    /**
     * @return BelongsTo<ItemSerial, $this>
     */
    public function newSerial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'new_item_serial_id');
    }

    /**
     * @return BelongsTo<DeliveryLine, $this>
     */
    public function deliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class);
    }
}
