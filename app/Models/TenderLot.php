<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lot of a tender (awarded separately): purchase items or one transport spec (lane/shipment).
 */
#[Fillable(['tender_id', 'number', 'title', 'description'])]
class TenderLot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['number' => 'integer'];
    }

    /**
     * @return BelongsTo<Tender, $this>
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class)->withTrashed();
    }

    /**
     * @return HasMany<TenderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TenderItem::class, 'lot_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<TenderTransportSpec, $this>
     */
    public function transportSpec(): HasOne
    {
        return $this->hasOne(TenderTransportSpec::class, 'lot_id');
    }

    /**
     * @return HasMany<TenderBidLot, $this>
     */
    public function bidLots(): HasMany
    {
        return $this->hasMany(TenderBidLot::class, 'lot_id');
    }

    /**
     * @return HasMany<TenderAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(TenderAward::class, 'lot_id')->orderBy('id');
    }
}
