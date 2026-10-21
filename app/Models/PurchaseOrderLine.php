<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Purchase order line: snapshot of the tender item and the awarded bid price.
 *
 * @property CarbonImmutable|null $required_by
 */
#[Fillable([
    'purchase_order_id', 'position', 'item_id', 'tender_item_id', 'sku', 'name', 'unit_code', 'unit_precision', 'quantity',
    'unit_price_minor', 'line_total_minor', 'hs_code', 'country_of_origin', 'gross_weight_kg', 'net_weight_kg', 'volume_m3',
    'packages_count', 'package_type', 'weight_source', 'offered_brand', 'offered_model', 'required_by',
])]
class PurchaseOrderLine extends Model
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
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'required_by' => 'immutable_date',
            'gross_weight_kg' => 'decimal:3',
            'net_weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:3',
            'packages_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
