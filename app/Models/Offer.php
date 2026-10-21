<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Enums\DataScope;
use App\Enums\Locale;
use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Models\Concerns\LogsActivity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Commercial offer, one row per version (Spec 05 section 7.1, DEC-2). Content (lines, groups) is
 * changed only through App\Actions\Offers; totals only by OfferCalculator via RecalculateOffer;
 * status only by TransitionOffer.
 *
 * @property OfferStatus $status
 * @property CarbonImmutable $valid_until
 * @property SelectionChannel|null $sent_channel
 * @property SelectionChannel|null $accepted_via
 * @property array<string, mixed>|null $accepted_selection
 */
#[Fillable([
    'number', 'version', 'root_offer_id', 'previous_version_id', 'status', 'lead_id', 'customer_id', 'contact_id', 'owner_id',
    'currency', 'prices_include_vat', 'valid_until', 'payment_terms_days', 'payment_terms_text',
    'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes', 'internal_notes', 'document_locale',
    'requisition_id',
])]
class Offer extends Model implements HasDocumentLanguage
{
    /** @use HasFactory<OfferFactory> */
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
            'status' => OfferStatus::class,
            'valid_until' => 'immutable_date',
            'prices_include_vat' => 'boolean',
            'selection_complete' => 'boolean',
            'payment_terms_days' => 'integer',
            'fixed_net_minor' => 'integer',
            'fixed_vat_minor' => 'integer',
            'fixed_gross_minor' => 'integer',
            'selected_net_minor' => 'integer',
            'selected_vat_minor' => 'integer',
            'selected_gross_minor' => 'integer',
            'range_min_gross_minor' => 'integer',
            'range_max_gross_minor' => 'integer',
            'recommended_gross_minor' => 'integer',
            'pipeline_value_minor' => 'integer',
            'max_discount_bp' => 'integer',
            'selection_revision' => 'integer',
            'sent_channel' => SelectionChannel::class,
            'accepted_via' => SelectionChannel::class,
            'accepted_selection' => 'array',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'first_viewed_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * Lead this record came from (Spec 04), if any.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_offer_id');
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /**
     * @return HasMany<OfferOptionGroup, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(OfferOptionGroup::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<OfferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OfferLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<OfferLineSelection, $this>
     */
    public function selections(): HasMany
    {
        return $this->hasMany(OfferLineSelection::class);
    }

    /**
     * @return HasMany<OfferSelectionEvent, $this>
     */
    public function selectionEvents(): HasMany
    {
        return $this->hasMany(OfferSelectionEvent::class)->latest('created_at')->latest('id');
    }

    /**
     * @return HasMany<OfferApproval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(OfferApproval::class)->latest('id');
    }

    /**
     * @return HasOne<OfferVersion, $this>
     */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(OfferVersion::class);
    }

    /**
     * The order created from this (accepted) offer version (Spec 06 DEC-1).
     *
     * @return HasOne<Order, $this>
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->latest('created_at')->latest('id');
    }

    /**
     * All versions of this offer (same root), oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Offer>
     */
    public function versions(): \Illuminate\Database\Eloquent\Collection
    {
        return self::withTrashed()->where(fn ($query) => $query->whereKey($this->rootId())->orWhere('root_offer_id', $this->rootId()))->orderBy('version')->get();
    }

    public function rootId(): int
    {
        return $this->root_offer_id ?? $this->id;
    }

    /**
     * "OF-2026-00042" for version 1, "OF-2026-00042/2" afterwards (BR-25).
     */
    public function displayNumber(): string
    {
        return $this->version > 1 ? $this->number.'/'.$this->version : $this->number;
    }

    public function money(?int $minor): ?Money
    {
        return $minor === null ? null : Money::of($minor, $this->currency);
    }

    /** End of the validity day in the offers timezone (valid_until is inclusive). */
    public function isExpiredByDate(?CarbonImmutable $now = null): bool
    {
        $timezone = (string) config('offers.timezone');
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->toDateString();

        return $this->valid_until->toDateString() < $today;
    }

    public function hasAlternatives(): bool
    {
        return $this->groups->isNotEmpty();
    }

    /**
     * Ordered blocks: fixed lines and option groups share one numbering (1, 2, 3, ...); options
     * are labelled "3A", "3B". Needs `lines` and `groups` loaded.
     *
     * @return Collection<int, array{type: string, number: int, line?: OfferLine, group?: OfferOptionGroup, lines?: Collection<int, OfferLine>}>
     */
    public function blocks(): Collection
    {
        $lines = $this->lines;
        $blocks = collect();

        foreach ($lines->whereNull('option_group_id') as $line) {
            $blocks->push(['type' => 'line', 'position' => $line->position, 'id' => $line->id, 'line' => $line]);
        }

        foreach ($this->groups as $group) {
            $blocks->push([
                'type' => 'group', 'position' => $group->position, 'id' => $group->id, 'group' => $group,
                'lines' => $lines->where('option_group_id', $group->id)->sortBy([['position', 'asc'], ['id', 'asc']])->values(),
            ]);
        }

        return $blocks
            ->sortBy([['position', 'asc'], ['type', 'desc'], ['id', 'asc']])
            ->values()
            ->map(function (array $block, int $index): array {
                unset($block['position'], $block['id']);

                return ['number' => $index + 1, ...$block];
            });
    }

    /**
     * Offer line ids currently selected by the customer.
     *
     * @return list<int>
     */
    public function selectedLineIds(): array
    {
        return $this->selections()->pluck('offer_line_id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Offers the user may see (Spec 05 4.1): own + own customers' for sales (linked), department
     * for heads, all for manager/finance/admin.
     *
     * @param  Builder<Offer>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $scope = PermissionMap::scopeFor($user, 'offers.view');

        match ($scope) {
            DataScope::All => null,
            DataScope::Department => $query->whereIn($query->qualifyColumn('owner_id'), [$user->id, ...PermissionMap::colleagueIds($user)]),
            DataScope::Linked => $query->where(fn (Builder $q) => $q
                ->where($q->qualifyColumn('owner_id'), $user->id)
                ->orWhereIn($q->qualifyColumn('customer_id'), Customer::withTrashed()->select('id')->where('owner_id', $user->id))),
            DataScope::Own => $query->where($query->qualifyColumn('owner_id'), $user->id),
            null => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Document language: the offer's override, else the customer's preference.
     */
    public function preferredDocumentLocale(): ?Locale
    {
        return Locale::fromAvailable($this->document_locale) ?? $this->customer?->preferredDocumentLocale();
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        return in_array('status', $changedKeys, true) ? 'status_changed' : 'updated';
    }

    /**
     * Totals, hashes and bookkeeping are recalculated constantly; they are not change-log material.
     *
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return [
            'uuid', 'fixed_net_minor', 'fixed_vat_minor', 'fixed_gross_minor', 'selected_net_minor', 'selected_vat_minor',
            'selected_gross_minor', 'range_min_gross_minor', 'range_max_gross_minor', 'recommended_gross_minor',
            'pipeline_value_minor', 'max_discount_bp', 'selection_complete', 'selection_revision', 'content_hash',
            'approved_hash', 'accepted_hash', 'accepted_selection',
        ];
    }

    /**
     * The customer request this offer answers (Spec 10).
     *
     * @return BelongsTo<Requisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }
}
