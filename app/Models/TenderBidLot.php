<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Offer of a bid for one lot (Spec 08: one variant per lot for now). `total_minor` is in the bid
 * currency and always recomputed server-side from lines; landed cost, score, rank, knock-out
 * reasons and the cost breakdown are written by the evaluation (evaluation currency).
 *
 * @property list<string>|null $knockout_reasons
 * @property array<string, mixed>|null $breakdown
 */
#[Fillable([
    'bid_id', 'lot_id', 'variant_no', 'variant_label', 'total_minor', 'transit_days', 'lead_time_days', 'route', 'border_crossing',
    'vehicle_offered', 'downtime_rate_minor_per_day', 'payment_terms_offered', 'exceptions',
])]
class TenderBidLot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variant_no' => 'integer',
            'total_minor' => 'integer',
            'transit_days' => 'integer',
            'lead_time_days' => 'integer',
            'downtime_rate_minor_per_day' => 'integer',
            'landed_cost_minor' => 'integer',
            'uses_estimates' => 'boolean',
            'score' => 'decimal:3',
            'rank' => 'integer',
            'knockout_reasons' => 'array',
            'breakdown' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TenderBid, $this>
     */
    public function bid(): BelongsTo
    {
        return $this->belongsTo(TenderBid::class, 'bid_id');
    }

    /**
     * @return BelongsTo<TenderLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(TenderLot::class, 'lot_id');
    }

    /**
     * @return HasMany<TenderBidLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(TenderBidLine::class, 'bid_lot_id')->orderBy('id');
    }

    /**
     * @return HasMany<TenderScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(TenderScore::class, 'bid_lot_id');
    }

    public function isKnockedOut(): bool
    {
        return ! empty($this->knockout_reasons);
    }
}
