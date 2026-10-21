<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\ServiceTicketStatus;
use App\Enums\ServiceUrgency;
use App\Models\Concerns\LogsActivity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Service (repair) request for one customer asset (Spec 09 §3.3, §5.2) with SLA due times, a
 * warranty snapshot, parts, notes (internal or public), photos and documents. Billable tickets
 * closed outside warranty become `service_ticket.closed` in the 1C outbox (pull-only).
 *
 * @property ServiceTicketStatus $status
 * @property ServiceUrgency $urgency
 * @property CarbonImmutable|null $respond_due_at
 * @property CarbonImmutable|null $repair_due_at
 */
#[Fillable(['partner_id', 'customer_asset_id', 'number', 'status', 'urgency', 'problem', 'contact_name', 'contact_phone', 'location', 'source', 'telegram_chat_id', 'reported_by', 'assigned_to', 'respond_due_at', 'under_warranty', 'warranty_until', 'currency', 'queued_at', 'queue_rank', 'queue_item_id', 'queue_need', 'queue_eta'])]
class ServiceTicket extends Model
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
            'queued_at' => 'immutable_datetime',
            'queue_rank' => 'integer',
            'queue_eta' => 'immutable_date',
            'status' => ServiceTicketStatus::class,
            'urgency' => ServiceUrgency::class,
            'under_warranty' => 'boolean',
            'warranty_until' => 'immutable_date',
            'accepted_at' => 'immutable_datetime',
            'respond_due_at' => 'immutable_datetime',
            'repair_due_at' => 'immutable_datetime',
            'ready_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'onec_acked_at' => 'immutable_datetime',
            'labor_minor' => 'integer',
            'telegram_chat_id' => 'integer',
        ];
    }

    /**
     * Records the user may read (Spec 09 §7): everything for `service.view` scope "all", else the
     * customers they (or their department) own.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (PermissionMap::scopeFor($user, 'service.view') === DataScope::All) {
            return;
        }

        $query->whereHas('organization', fn (Builder $partner) => PermissionMap::applyScope($partner, $user, 'service.view'));
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id')->withTrashed();
    }

    /**
     * Customer view of the organization (null when it no longer has the customer role).
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'partner_id')->withTrashed();
    }

    /**
     * Item the queued ticket waits for (Spec 09 section 15).
     *
     * @return BelongsTo<Item, $this>
     */
    public function queueItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'queue_item_id')->withTrashed();
    }

    /**
     * @return BelongsTo<CustomerAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(CustomerAsset::class, 'customer_asset_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<ServiceTicketPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(ServiceTicketPart::class)->orderBy('id');
    }

    /**
     * @return HasMany<ServiceTicketNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(ServiceTicketNote::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<ServiceTicketPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ServiceTicketPhoto::class)->orderBy('id');
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

    public function partsMinor(): int
    {
        return (int) $this->parts->sum(fn (ServiceTicketPart $part): int => $this->money($part->unit_price_minor)->multiplyByQty((string) $part->quantity)->amount);
    }

    public function totalMinor(): int
    {
        return (int) $this->labor_minor + $this->partsMinor();
    }

    public function isBillable(): bool
    {
        return ! $this->under_warranty && $this->totalMinor() > 0;
    }

    public function money(int $minor): Money
    {
        return Money::of($minor, (string) ($this->currency ?: config('crm.base_currency')));
    }

    public function respondOverdue(): bool
    {
        return $this->status === ServiceTicketStatus::New && $this->respond_due_at !== null && $this->respond_due_at->isPast();
    }

    public function repairOverdue(): bool
    {
        return $this->repair_due_at !== null && $this->ready_at === null && $this->status->isOpen() && $this->repair_due_at->isPast();
    }

    public function isOverdue(): bool
    {
        return $this->respondOverdue() || $this->repairOverdue();
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'respond_due_at', 'repair_due_at', 'onec_state', 'onec_acked_at'];
    }
}
