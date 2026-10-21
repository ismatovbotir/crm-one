<?php

namespace App\Models;

use App\Enums\ChargeCode;
use App\Enums\ChargeInclusion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bid line: a transport charge (charge_code, inclusion, amount; owner's estimate when not
 * included) or a purchase price for a tender item (unit price × quantity, offered brand/model,
 * compliance). `amount_minor` is computed server-side.
 *
 * @property ChargeCode|null $charge_code
 * @property ChargeInclusion $inclusion
 */
#[Fillable([
    'bid_lot_id', 'tender_item_id', 'charge_code', 'description', 'basis', 'quantity', 'unit_price_minor', 'amount_minor',
    'inclusion', 'offered_brand', 'offered_model', 'compliant', 'deviation',
])]
class TenderBidLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'charge_code' => ChargeCode::class,
            'inclusion' => ChargeInclusion::class,
            'quantity' => 'decimal:3',
            'unit_price_minor' => 'integer',
            'amount_minor' => 'integer',
            'estimate_minor' => 'integer',
            'compliant' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TenderItem, $this>
     */
    public function tenderItem(): BelongsTo
    {
        return $this->belongsTo(TenderItem::class);
    }
}
