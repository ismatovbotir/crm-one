<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\TenderKind;
use App\Enums\TenderMethod;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\TenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Purchase or transport tender (Spec 03 7.3, DEC-11). Status changes only through
 * TransitionTender; bids are sealed until `opened_at` (BR-T.5). Budget, target prices and
 * incumbent data are internal only (BR-T.12). Only drafts may be (soft) deleted.
 *
 * @property TenderType $type
 * @property TenderStatus $status
 * @property TenderMethod $method
 * @property TenderKind $kind
 * @property list<string> $accepted_currencies
 * @property array<string, array{rate: string, date: string, source: string}>|null $fx_rates
 * @property array{checked_at: string, issues: list<array{code: string, level: string, message: string, field?: string|null}>}|null $completeness
 * @property array<string, string>|null $acknowledged_warnings
 * @property CarbonImmutable|null $bids_due_at
 * @property CarbonImmutable|null $opened_at
 */
#[Fillable([
    'number', 'type', 'status', 'method', 'kind', 'title', 'description', 'department_id', 'owner_id', 'requested_by',
    'notice_locale', 'evaluation_currency', 'accepted_currencies', 'budget_minor', 'budget_currency',
    'incumbent_counterparty_id', 'incumbent_disclosed', 'incumbent_rate_minor', 'payment_terms', 'payment_days',
    'prepayment_bp', 'bid_validity_days', 'confidential', 'link_verification_required', 'urgent', 'urgent_justification', 'single_source_justification',
    'incoterm', 'incoterm_place', 'delivery_warehouse_id', 'delivery_address', 'partial_deliveries_allowed', 'is_import',
    'required_certificates', 'warranty_terms', 'expected_downtime_days', 'lane_period_from', 'lane_period_to',
    'shipments_per_month', 'clarification_deadline_at', 'bids_due_at', 'round', 'parent_tender_id',
])]
class Tender extends Model
{
    /** @use HasFactory<TenderFactory> */
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

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
            'type' => TenderType::class,
            'status' => TenderStatus::class,
            'method' => TenderMethod::class,
            'kind' => TenderKind::class,
            'accepted_currencies' => 'array',
            'fx_rates' => 'array',
            'completeness' => 'array',
            'acknowledged_warnings' => 'array',
            'budget_minor' => 'integer',
            'incumbent_rate_minor' => 'integer',
            'incumbent_disclosed' => 'boolean',
            'payment_days' => 'integer',
            'prepayment_bp' => 'integer',
            'bid_validity_days' => 'integer',
            'confidential' => 'boolean',
            'link_verification_required' => 'boolean',
            'urgent' => 'boolean',
            'partial_deliveries_allowed' => 'boolean',
            'is_import' => 'boolean',
            'expected_downtime_days' => 'decimal:2',
            'lane_period_from' => 'immutable_date',
            'lane_period_to' => 'immutable_date',
            'shipments_per_month' => 'integer',
            'fx_set_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'clarification_deadline_at' => 'immutable_datetime',
            'bids_due_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'awarded_at' => 'immutable_datetime',
            'contracted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'round' => 'integer',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function incumbent(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'incumbent_counterparty_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function deliveryWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'delivery_warehouse_id');
    }

    /**
     * @return BelongsTo<Tender, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_tender_id')->withTrashed();
    }

    /**
     * @return HasMany<TenderLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(TenderLot::class)->orderBy('number');
    }

    /**
     * @return HasMany<TenderSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(TenderSource::class);
    }

    /**
     * @return HasMany<TenderCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(TenderCriterion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<TenderRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(TenderRequirement::class)->orderBy('id');
    }

    /**
     * @return HasMany<TenderCommitteeMember, $this>
     */
    public function committee(): HasMany
    {
        return $this->hasMany(TenderCommitteeMember::class)->orderBy('id');
    }

    /**
     * @return HasMany<TenderInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TenderInvitation::class)->orderBy('id');
    }

    /**
     * @return HasMany<TenderQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(TenderQuestion::class)->orderBy('id');
    }

    /**
     * @return HasMany<TenderAddendum, $this>
     */
    public function addenda(): HasMany
    {
        return $this->hasMany(TenderAddendum::class)->orderBy('version');
    }

    /**
     * @return HasMany<TenderBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(TenderBid::class);
    }

    /**
     * @return HasMany<TenderAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(TenderAward::class)->orderBy('lot_id')->orderBy('id');
    }

    /**
     * @return HasMany<TenderDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(TenderDecision::class)->orderBy('id');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->orderBy('id');
    }

    public function isOpened(): bool
    {
        return $this->opened_at !== null;
    }

    public function isCommitteeMember(User $user): bool
    {
        return $this->committee()->where('user_id', $user->id)->exists();
    }

    /** Owner, requester or a member of the owning department. */
    public function involves(User $user): bool
    {
        return $this->owner_id === $user->id || $this->requested_by === $user->id;
    }

    /** The view ability of the tender type. */
    public function viewAbility(): string
    {
        return 'tenders.'.$this->type->value.'.view';
    }

    /**
     * Tenders the user may see: per type, the scope of `tenders.<type>.view` (own/linked = owned or
     * requested; department = owned by colleagues or in one of the user's departments; all) plus
     * tenders where the user sits on the committee.
     *
     * @param  Builder<Tender>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $outer) use ($user): void {
            foreach (TenderType::cases() as $type) {
                $scope = PermissionMap::scopeFor($user, 'tenders.'.$type->value.'.view');

                if ($scope === null) {
                    continue;
                }

                $outer->orWhere(function (Builder $q) use ($type, $scope, $user): void {
                    $q->where('type', $type->value);

                    if ($scope === DataScope::All) {
                        return;
                    }

                    $q->where(function (Builder $w) use ($scope, $user): void {
                        $w->where('owner_id', $user->id)->orWhere('requested_by', $user->id)
                            // Linked: tenders sourced from the user's customer orders or purchase orders (Spec 03 US-8, Spec 08 4).
                            ->orWhereHas('sources', fn (Builder $s) => $s->where(fn (Builder $o) => $o
                                ->where(fn (Builder $x) => $x->where('source_type', 'order')->whereIn('source_id', Order::query()->select('id')->where('owner_id', $user->id)))
                                ->orWhere(fn (Builder $x) => $x->where('source_type', 'purchase_order')->whereIn('source_id', PurchaseOrder::query()->select('id')->where('owner_id', $user->id)))));

                        if ($scope === DataScope::Department) {
                            $w->orWhereIn('owner_id', PermissionMap::colleagueIds($user))
                                ->orWhereIn('department_id', $user->activeDepartments()->pluck('id')->all());
                        }
                    });
                });
            }

            $outer->orWhereHas('committee', fn (Builder $c) => $c->where('user_id', $user->id));
        });
    }

    /**
     * Deadline shown in the business timezone (BR-T.13).
     */
    public function localTime(?CarbonImmutable $moment): ?CarbonImmutable
    {
        return $moment?->setTimezone((string) config('tenders.timezone'));
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'completeness'];
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        return in_array('status', $changedKeys, true) ? 'status_changed' : 'updated';
    }
}
