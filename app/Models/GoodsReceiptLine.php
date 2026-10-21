<?php

namespace App\Models;

use App\Enums\DiscrepancyReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods receipt line (Spec 06 21.5): expected vs received quantity, damaged units, discrepancy
 * reason; serialized lines carry one serial row per received unit.
 *
 * @property string $received_qty
 * @property string|null $expected_qty
 * @property string $damaged_qty
 * @property DiscrepancyReason|null $discrepancy_reason
 */
#[Fillable([
    'receipt_id', 'position', 'item_id', 'is_serialized', 'return_of_delivery_line_id', 'expected_qty', 'received_qty', 'damaged_qty',
    'condition', 'discrepancy_reason', 'note', 'external_line_id',
])]
class GoodsReceiptLine extends Model
{
    public const LINE_TYPE = 'goods_receipt_line';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_serialized' => 'boolean',
            'expected_qty' => 'decimal:3',
            'received_qty' => 'decimal:3',
            'damaged_qty' => 'decimal:3',
            'discrepancy_reason' => DiscrepancyReason::class,
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'receipt_id');
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

    /**
     * @param  Builder<GoodsReceiptLine>  $query
     */
    public function scopeSerialized(Builder $query): void
    {
        $query->where('is_serialized', true);
    }
}
