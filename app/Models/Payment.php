<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\MatchState;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentSource;
use App\Enums\PaymentStatus;
use App\Models\Concerns\LogsActivity;
use App\Services\OneC\Inbound\OneCSyncContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Payment (Spec 06 7.11, DEC-3): immutable money and identity. Corrections are a void (second
 * person approves) plus a new payment; refunds are outgoing payments. Only 1C corrections may
 * change money columns, inside OneCSyncContext (book of record, audited by ledger entries).
 *
 * @property PaymentStatus $status
 * @property PaymentSource $source
 * @property PaymentKind $kind
 * @property PaymentMethod $method
 * @property MatchState|null $match_state
 * @property CarbonImmutable $paid_on
 */
#[Fillable([
    'number', 'direction', 'kind', 'status', 'source', 'customer_id', 'amount_minor', 'currency', 'paid_on', 'method',
    'company_bank_account_id', 'payer_name', 'payer_tax_id', 'payer_account', 'reference_number', 'purpose', 'note',
    'refund_of_payment_id', 'proof_attachment_id', 'period', 'recorded_by', 'duplicate_of_id', 'match_state',
    'posted_in_locked_period', 'planned_order_id', 'onec_guid', 'onec_number', 'onec_posted', 'onec_version', 'onec_updated_at',
])]
class Payment extends Model
{
    use HasUuids, LogsActivity;

    /** Money and identity: never changed after creation (except by 1C corrections). */
    public const IMMUTABLE = ['amount_minor', 'currency', 'paid_on', 'customer_id', 'direction', 'kind', 'number', 'source', 'refund_of_payment_id'];

    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            $dirty = array_intersect(array_keys($payment->getDirty()), self::IMMUTABLE);
            $allowed = OneCSyncContext::active() ? ['amount_minor', 'currency', 'paid_on', 'customer_id'] : [];

            // Linking an unmatched 1C payment to a customer is the one CRM-side identity change.
            if ($payment->getRawOriginal('customer_id') === null) {
                $allowed[] = 'customer_id';
            }

            if (array_diff($dirty, $allowed) !== []) {
                throw new LogicException('Payments are immutable; void and record a new payment.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Payments are never deleted.'));
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
            'status' => PaymentStatus::class,
            'source' => PaymentSource::class,
            'kind' => PaymentKind::class,
            'method' => PaymentMethod::class,
            'match_state' => MatchState::class,
            'amount_minor' => 'integer',
            'allocated_minor' => 'integer',
            'refunded_minor' => 'integer',
            'paid_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'void_requested_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'onec_updated_at' => 'immutable_datetime',
            'onec_acked_at' => 'immutable_datetime',
            'onec_posted' => 'boolean',
            'posted_in_locked_period' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<CompanyBankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyBankAccount::class, 'company_bank_account_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_payment_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refund_of_payment_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<Attachment, $this>
     */
    public function proof(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'proof_attachment_id');
    }

    public function money(?int $minor = null): Money
    {
        return Money::of($minor ?? $this->amount_minor, $this->currency);
    }

    /** Amount still free for allocation or refund. */
    public function unallocatedMinor(): int
    {
        return max(0, $this->amount_minor - $this->allocated_minor - $this->refunded_minor);
    }

    /** Counts toward balances: status counts and not an unresolved possible duplicate. */
    public function counts(): bool
    {
        return $this->kind === PaymentKind::Receipt && $this->status->counts() && $this->match_state !== MatchState::PossibleDuplicate && $this->onec_posted !== false;
    }

    /**
     * Payments the user may see (Spec 06 4.3 `payments.view`): sales only payments allocated to
     * their orders (or of customers they own); accountants and finance all.
     *
     * @param  Builder<Payment>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $scope = PermissionMap::scopeFor($user, 'payments.view');

        if ($scope === DataScope::All) {
            return;
        }

        if ($scope === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $owners = $scope === DataScope::Department ? [$user->id, ...PermissionMap::colleagueIds($user)] : [$user->id];

        $query->where(fn (Builder $visible) => $visible
            ->whereIn($query->qualifyColumn('id'), PaymentAllocation::query()->select('payment_id')->whereIn('order_id', Order::query()->select('id')->whereIn('owner_id', $owners)))
            ->orWhereIn($query->qualifyColumn('customer_id'), Customer::withTrashed()->select('id')->whereIn('owner_id', $owners)));
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'allocated_minor', 'refunded_minor', 'payer_account', 'onec_version', 'onec_updated_at'];
    }
}
