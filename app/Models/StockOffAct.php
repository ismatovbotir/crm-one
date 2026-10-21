<?php

namespace App\Models;

use App\Enums\StockOffReason;
use App\Enums\StockOffStatus;
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
 * Stock write-off / damage act (Spec 06 section 27): one document per stock-off event. Created
 * automatically as a draft when a goods receipt is posted with damaged units, or by the stock
 * manager. Approved by the warehouse head (CEO / finance above the value limit), posted by stock
 * staff; posted acts are immutable and never deleted.
 *
 * @property StockOffStatus $status
 * @property StockOffReason $reason
 * @property array<string, int>|null $totals
 * @property CarbonImmutable|null $posted_at
 */
#[Fillable([
    'number', 'status', 'reason', 'note', 'warehouse_id', 'goods_receipt_id', 'supplier_partner_id', 'claim_status', 'claim_ref',
    'claim_note', 'claim_settled_at', 'totals', 'approval_level', 'created_by',
])]
class StockOffAct extends Model
{
    use HasUuids, LogsActivity;

    public const DOCUMENT_TYPE = 'stock_off_act';

    public const CLAIM_STATUSES = ['none', 'open', 'settled', 'rejected'];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Stock-off acts are never deleted; cancel them.'));
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
            'status' => StockOffStatus::class,
            'reason' => StockOffReason::class,
            'totals' => 'array',
            'claim_settled_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'posted_at' => 'immutable_datetime',
            'onec_acked_at' => 'immutable_datetime',
        ];
    }

    /** Above the value limit the CEO / finance approves instead of the warehouse head. */
    public function needsHighApproval(): bool
    {
        return $this->approval_level === 'high';
    }

    /**
     * @return HasMany<StockOffLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockOffLine::class, 'act_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function supplierPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_partner_id')->withTrashed();
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
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid'];
    }
}
