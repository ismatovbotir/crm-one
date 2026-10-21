<?php

namespace App\Models;

use App\Enums\OrderAmendmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Change request on an order that already started (Spec 06 sections 6.4 and 7.4): one or more
 * changes (quantity, discount, new or removed line, close short) kept as a json list and applied
 * together, after approval when a discount goes above the requester's limit. Applied, rejected and
 * cancelled amendments stay as history and are never deleted.
 *
 * @property OrderAmendmentStatus $status
 * @property list<array<string, mixed>> $changes
 * @property array<string, int>|null $totals_before
 * @property array<string, int>|null $totals_after
 * @property CarbonImmutable|null $applied_at
 */
#[Fillable(['order_id', 'number', 'status', 'changes', 'reason', 'needs_approval', 'required_discount_bp', 'requested_by'])]
class OrderAmendment extends Model
{
    public const DOCUMENT_TYPE = 'order_amendment';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Order amendments are never deleted; cancel them.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderAmendmentStatus::class,
            'changes' => 'array',
            'needs_approval' => 'boolean',
            'required_discount_bp' => 'integer',
            'totals_before' => 'array',
            'totals_after' => 'array',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Agreement, $this>
     */
    public function addendum(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'addendum_agreement_id');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->orderBy('id');
    }
}
