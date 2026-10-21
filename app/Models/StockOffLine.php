<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stock-off act line (Spec 06 27.2): item, quantity (serialized: the number of picked serials),
 * estimated value from the warehouse stock cost, optional link to the goods receipt line.
 *
 * @property string $quantity
 */
#[Fillable(['act_id', 'position', 'item_id', 'is_serialized', 'quantity', 'receipt_line_id', 'unit_cost_minor', 'value_minor', 'currency', 'note'])]
class StockOffLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_serialized' => 'boolean', 'quantity' => 'decimal:3', 'unit_cost_minor' => 'integer', 'value_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<StockOffAct, $this>
     */
    public function act(): BelongsTo
    {
        return $this->belongsTo(StockOffAct::class, 'act_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return BelongsTo<GoodsReceiptLine, $this>
     */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'receipt_line_id');
    }

    /**
     * @return HasMany<StockOffLineSerial, $this>
     */
    public function serials(): HasMany
    {
        return $this->hasMany(StockOffLineSerial::class, 'line_id')->orderBy('serial_number');
    }
}
