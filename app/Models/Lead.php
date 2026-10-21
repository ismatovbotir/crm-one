<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\LeadCloseReason;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\SaleType;
use App\Models\Concerns\LogsActivity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * Sales lead (Spec 04): an enquiry with owner, source, free-text company/contact until a customer
 * is linked, BANT qualification and conversion to an offer. Status changes only through
 * App\Actions\Leads\TransitionLead; never deleted (closed as disqualified or lost instead).
 *
 * @property LeadStatus $status
 * @property LeadSource $source
 * @property LeadCloseReason|null $close_reason
 */
#[Fillable([
    'number', 'status', 'source', 'campaign', 'owner_id', 'created_by', 'customer_id', 'contact_id', 'company_name', 'tax_id',
    'industry', 'contact_name', 'contact_position', 'contact_email', 'contact_phone', 'need', 'est_value_minor', 'currency',
    'expected_close_date', 'budget_confirmed', 'authority_confirmed', 'need_confirmed', 'timeline_confirmed', 'close_reason',
    'close_note', 'qualified_at', 'converted_at', 'converted_by', 'conversion_kind', 'closed_at', 'last_activity_at', 'telegram_chat_id', 'telegram_user_id',
    'telegram_username', 'locale', 'priority', 'sale_type', 'score', 'duplicate_override_reason', 'merged_into_id',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory, HasUuids, LogsActivity;

    public const BANT = ['budget_confirmed', 'authority_confirmed', 'need_confirmed', 'timeline_confirmed'];

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Leads are never deleted; close them instead.'));
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['priority' => 'normal', 'score' => 0];

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
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'close_reason' => LeadCloseReason::class,
            'priority' => LeadPriority::class,
            'sale_type' => SaleType::class,
            'score' => 'integer',
            'est_value_minor' => 'integer',
            'expected_close_date' => 'immutable_date',
            'budget_confirmed' => 'boolean',
            'authority_confirmed' => 'boolean',
            'need_confirmed' => 'boolean',
            'timeline_confirmed' => 'boolean',
            'qualified_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
        ];
    }

    /**
     * Leads the user may see (Spec 04 section 3): own, department or all.
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        match (PermissionMap::scopeFor($user, 'leads.view')) {
            DataScope::All => null,
            // Unassigned leads (e.g. from Telegram) are visible to heads so they can assign them.
            DataScope::Department => $query->where(fn (Builder $q) => $q->whereIn($q->qualifyColumn('owner_id'), [$user->id, ...PermissionMap::colleagueIds($user)])->orWhereNull($q->qualifyColumn('owner_id'))),
            DataScope::Linked, DataScope::Own => $query->where($query->qualifyColumn('owner_id'), $user->id),
            null => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Open leads without activity for `leads.stale_days` days (Spec 04 BR-8).
     *
     * @param  Builder<Lead>  $query
     */
    public function scopeStale(Builder $query): void
    {
        $threshold = now()->subDays((int) config('leads.stale_days', 14));

        $query->whereIn('status', LeadStatus::openValues())
            ->where(fn (Builder $q) => $q->where('last_activity_at', '<', $threshold)
                ->orWhere(fn (Builder $inner) => $inner->whereNull('last_activity_at')->where('created_at', '<', $threshold)));
    }

    public function isStale(): bool
    {
        $last = $this->last_activity_at ?? ($this->created_at !== null ? CarbonImmutable::parse($this->created_at) : null);

        return $this->status->isOpen() && $last !== null && $last->lt(now()->subDays((int) config('leads.stale_days', 14)));
    }

    public function isUnassigned(): bool
    {
        return $this->owner_id === null;
    }

    /** Customer name when linked, otherwise the free-text company. */
    public function companyLabel(): string
    {
        return (string) ($this->customer?->name ?? $this->company_name ?? '—');
    }

    /** Linked contact name, otherwise the free-text contact. */
    public function contactLabel(): ?string
    {
        return $this->contact?->fullName() ?? $this->contact_name;
    }

    public function estimatedValue(): ?Money
    {
        return $this->est_value_minor === null ? null : Money::of($this->est_value_minor, $this->currency);
    }

    /**
     * What still blocks qualification (Spec 04 BR-4), as translation keys under leads.gate.*.
     *
     * @return list<string>
     */
    public function qualificationGaps(): array
    {
        $gaps = [];
        $customer = $this->customer;

        if ($customer === null) {
            $gaps[] = 'customer';
        } elseif ($customer->trashed() || $customer->isDeletedInOneC()) {
            $gaps[] = 'customer_unavailable';
        }

        if ($this->contact_id === null || ($customer !== null && $this->contact?->partner_id !== $customer->id)) {
            $gaps[] = 'contact';
        }

        if (blank($this->need)) {
            $gaps[] = 'need';
        }

        foreach (self::BANT as $flag) {
            if (! $this->{$flag}) {
                $gaps[] = $flag;
            }
        }

        return $gaps;
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
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
    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
     * @return HasMany<LeadItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LeadItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<Offer, $this>
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class)->orderBy('id');
    }

    /**
     * @return HasMany<LeadFollowUp, $this>
     */
    public function followUps(): HasMany
    {
        return $this->hasMany(LeadFollowUp::class)->orderBy('completed_at')->orderBy('due_at');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->orderBy('id');
    }

    /**
     * Bookkeeping columns kept out of the change log (status has its own history).
     *
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'last_activity_at'];
    }
}
