<?php

namespace App\Models;

use App\Enums\GoodsReceiptStatus;
use App\Enums\ReceiptSource;
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
 * Goods receipt (Spec 06 21.4): the stock manager receives goods and registers their serial
 * numbers; posting creates the serials. Posted receipts are immutable and never deleted.
 *
 * @property GoodsReceiptStatus $status
 * @property bool $needs_review
 * @property ReceiptSource $source_type
 * @property CarbonImmutable $received_on
 */
#[Fillable([
    'number', 'status', 'source_type', 'source_id', 'counterparty_name', 'customer_id', 'return_of_delivery_id', 'warehouse_id',
    'received_on', 'supplier_doc_ref', 'created_by', 'note', 'origin', 'external_id', 'external_number', 'external_date', 'supplier_partner_id', 'external_hash',
    'sync_version', 'external_updated_at', 'needs_review', 'review_reason',
])]
class GoodsReceipt extends Model
{
    use HasUuids, LogsActivity;

    public const DOCUMENT_TYPE = 'goods_receipt';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Goods receipts are never deleted; cancel the draft.'));
        static::creating(function (GoodsReceipt $receipt): void {
            $receipt->status ??= GoodsReceiptStatus::InProgress;
        });
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
            'external_date' => 'immutable_date',
            'status' => GoodsReceiptStatus::class,
            'external_updated_at' => 'immutable_datetime',
            'needs_review' => 'boolean',
            'sync_version' => 'integer',
            'source_type' => ReceiptSource::class,
            'received_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'onec_acked_at' => 'immutable_datetime',
        ];
    }

    /** Spec 06 section 26: supplier receipts come from 1C; the CRM only completes them. */
    public function isFromOneC(): bool
    {
        return $this->origin === 'onec';
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->orderBy('id');
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function supplierPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'supplier_partner_id')->withTrashed();
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class, 'receipt_id')->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function returnOfDelivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'return_of_delivery_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid'];
    }
}
