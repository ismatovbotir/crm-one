<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purchase tender line: 1C catalog item (snapshot) or free-text specification, quantity in the
 * unit's precision. `target_price_minor` is internal only (BR-T.12).
 *
 * @property CarbonImmutable|null $required_by
 */
#[Fillable([
    'lot_id', 'position', 'item_id', 'sku', 'name', 'unit_code', 'unit_precision', 'quantity', 'specification',
    'alternatives_allowed', 'required_by', 'hs_code', 'target_price_minor',
])]
class TenderItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'unit_precision' => 'integer',
            'quantity' => 'decimal:3',
            'alternatives_allowed' => 'boolean',
            'required_by' => 'immutable_date',
            'target_price_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TenderLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(TenderLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
