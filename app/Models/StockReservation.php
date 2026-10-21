<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Soft reservation of stock for an order line (Spec 06 7.5, DEC-8).
 *
 * @property ReservationStatus $status
 * @property string $quantity
 */
#[Fillable(['order_line_id', 'item_id', 'warehouse_id', 'quantity', 'status', 'released_at', 'release_reason'])]
class StockReservation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'quantity' => 'decimal:3',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
