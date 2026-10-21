<?php

namespace App\Models;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockAdjustmentType;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Stock adjustment (Spec 06 section 21.4, narrowed by 27.1): found serials, serial correction on a
 * posted delivery note and quantity correction. Requested by stock staff, approved and posted in
 * one step by the warehouse head or a manager (never the requester); posted documents are
 * immutable and never deleted.
 *
 * @property StockAdjustmentType $type
 * @property StockAdjustmentStatus $status
 * @property CarbonImmutable|null $posted_at
 */
#[Fillable(['number', 'type', 'status', 'warehouse_id', 'delivery_id', 'reason', 'created_by'])]
class StockAdjustment extends Model
{
    use HasUuids, LogsActivity;

    public const DOCUMENT_TYPE = 'stock_adjustment';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Stock adjustments are never deleted; cancel them.'));
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockAdjustmentType::class,
            'status' => StockAdjustmentStatus::class,
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StockAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class, 'adjustment_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->orderBy('id');
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid'];
    }
}
