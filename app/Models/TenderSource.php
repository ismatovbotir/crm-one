<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link of a tender to what triggered it (Spec 03 6.3): a customer order (transport request from
 * sales), later a purchase order (Spec 08).
 */
#[Fillable(['tender_id', 'source_type', 'source_id'])]
class TenderSource extends Model
{
    /**
     * @return BelongsTo<Tender, $this>
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_id');
    }
}
