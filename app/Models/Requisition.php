<?php

namespace App\Models;

use App\Enums\RequisitionStatus;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Customer requisition (Spec 10): a request from the client's team, reviewed and sent to us by
 * their purchase manager, answered with an offer (`offers.requisition_id`). Holds no prices.
 *
 * @property RequisitionStatus $status
 * @property CarbonImmutable|null $needed_by
 */
#[Fillable(['partner_id', 'number', 'status', 'requester_id', 'purchase_manager_id', 'title', 'delivery_location', 'needed_by', 'notes', 'owner_id', 'split_from_id'])]
class Requisition extends Model
{
    use HasUuids, LogsActivity;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'needed_by' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'partner_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function purchaseManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'purchase_manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<RequisitionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RequisitionLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<RequisitionComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(RequisitionComment::class)->orderBy('id');
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderByDesc('id');
    }

    /**
     * @return BelongsTo<Requisition, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject');
    }

    /** The latest offer sent to the customer (what the portal may show). */
    public function sentOffer(): ?Offer
    {
        return $this->offers()->whereNotNull('sent_at')->first();
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid'];
    }
}
