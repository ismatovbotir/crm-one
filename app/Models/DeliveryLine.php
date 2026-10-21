<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Delivery note line (Spec 06 7.6): serialized lines take their quantity from the number of
 * active serial rows (one per unit); other lines carry a plain quantity.
 *
 * @property string $quantity
 */
#[Fillable(['delivery_id', 'order_line_id', 'position', 'item_id', 'is_serialized', 'quantity'])]
class DeliveryLine extends Model
{
    public const LINE_TYPE = 'delivery_line';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_serialized' => 'boolean', 'quantity' => 'decimal:3', 'returned_qty' => 'decimal:3'];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * Active serial rows (draft or posted).
     *
     * @return HasMany<DocumentLineSerial, $this>
     */
    public function serialRows(): HasMany
    {
        return $this->hasMany(DocumentLineSerial::class, 'line_id')
            ->where('line_type', self::LINE_TYPE)
            ->whereIn('status', [DocumentLineSerial::DRAFT, DocumentLineSerial::POSTED])
            ->orderBy('position')->orderBy('id');
    }
}
