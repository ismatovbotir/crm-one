<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\DeliveryMethod;
use App\Enums\HoldReason;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\LegalType;
use App\Enums\Locale;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Concerns\LogsActivity;
use App\Support\Money;
use Database\Factories\OrderFactory;
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
 * Customer order (Spec 06 Part A). Created only from an accepted offer (CreateOrderFromOffer);
 * status changes only through TransitionOrder. Never deleted.
 *
 * @property OrderStatus $status
 * @property OrderPaymentStatus $payment_status
 * @property list<array{reason: string, set_at: string, overridden_by?: int|null, overridden_at?: string|null, override_reason?: string|null}>|null $hold_reasons
 */
#[Fillable([
    'number', 'status', 'payment_status', 'offer_id', 'offer_version', 'offer_accepted_hash', 'lead_id', 'customer_id',
    'contact_id', 'billing_contact_id', 'owner_id', 'framework_agreement_id', 'currency', 'prices_include_vat', 'net_minor',
    'vat_minor', 'gross_minor', 'payment_terms_days', 'prepayment_bp', 'payment_terms_text', 'delivery_terms_text',
    'warranty_text', 'delivery_address', 'requested_delivery_on', 'promised_delivery_on', 'document_locale',
    'agreement_required', 'sale_type', 'source', 'delivery_method', 'warehouse_id', 'note',
])]
class Order extends Model implements HasDocumentLanguage
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids, LogsActivity;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Orders are never deleted; cancel instead.'));
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Defaults matching the database (sale type b2b, Spec 06 section 25).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['sale_type' => 'b2b'];

    /**
     * Spec 06 section 28: default for "power of attorney required" — B2B orders of legal
     * entities, government bodies and sole proprietors; never retail or private persons.
     */
    public static function poaRequiredByDefault(SaleType $saleType, ?Partner $customer): bool
    {
        return ! $saleType->isRetail() && $customer !== null && $customer->legal_type !== LegalType::Individual;
    }

    /**
     * Spec 06 section 31: with no confirmation step, delivery details and the sale type stay
     * editable until packaging starts (and, for the sale type, before any payment).
     */
    public function isBeforeFulfilment(): bool
    {
        return in_array($this->status, [OrderStatus::New, OrderStatus::Confirmed], true) && $this->started_at === null;
    }

    /** The tax invoice may be registered: no POA needed, POA received, or the gate overridden. */
    public function poaSatisfied(): bool
    {
        return ! $this->poa_required || $this->poa_received_at !== null || $this->poa_waived_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'prices_include_vat' => 'boolean',
            'net_minor' => 'integer',
            'vat_minor' => 'integer',
            'gross_minor' => 'integer',
            'paid_minor' => 'integer',
            'invoiced_gross_minor' => 'integer',
            'payment_terms_days' => 'integer',
            'prepayment_bp' => 'integer',
            'agreement_required' => 'boolean',
            'sale_type' => SaleType::class,
            'poa_required' => 'boolean',
            'source' => OrderSource::class,
            'delivery_method' => DeliveryMethod::class,
            'poa_received_at' => 'immutable_datetime',
            'poa_waived_at' => 'immutable_datetime',
            'packed_at' => 'immutable_datetime',
            'addendum_pending' => 'boolean',
            'hold_reasons' => 'array',
            'on_hold' => 'boolean',
            'shortage_acknowledged' => 'boolean',
            'requested_delivery_on' => 'immutable_date',
            'promised_delivery_on' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'invoiced_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'onec_acked_at' => 'immutable_datetime',
            'revision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withTrashed();
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
     * @return BelongsTo<Contact, $this>
     */
    public function billingContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'billing_contact_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isManual(): bool
    {
        return $this->source === OrderSource::Manual;
    }

    public function isPickup(): bool
    {
        return $this->delivery_method === DeliveryMethod::Pickup;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poaReceiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'poa_received_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poaWaiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'poa_waived_by');
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Recomputes `addendum_pending` from the addenda of applied amendments: pending while one is still
     * a draft, in review or out for signature (Spec 06 section 6.4); settled once signed or cancelled.
     */
    public function syncAddendumPending(): void
    {
        $pending = $this->amendments()->whereHas('addendum', fn ($query) => $query->whereIn('status', [AgreementStatus::Draft, AgreementStatus::InReview, AgreementStatus::SentForSignature]))->exists();

        if ($pending !== (bool) $this->addendum_pending) {
            $this->forceFill(['addendum_pending' => $pending])->save();
        }
    }

    /**
     * Framework agreement this order runs under (Spec 06 section 31), when linked.
     *
     * @return BelongsTo<Agreement, $this>
     */
    public function frameworkAgreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class, 'framework_agreement_id');
    }

    /**
     * Change requests after the order started (Spec 06 section 6.4), newest first.
     *
     * @return HasMany<OrderAmendment, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(OrderAmendment::class)->orderByDesc('number');
    }

    /**
     * @return HasMany<Agreement, $this>
     */
    public function agreements(): HasMany
    {
        return $this->hasMany(Agreement::class)->latest('id');
    }

    /**
     * Active payment schedule (Spec 06 7.7), in order.
     *
     * @return HasMany<PaymentScheduleItem, $this>
     */
    public function scheduleItems(): HasMany
    {
        return $this->hasMany(PaymentScheduleItem::class)->whereNull('superseded_at')->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class)->orderBy('id');
    }

    /**
     * @return HasMany<Delivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class)->latest('id');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest('issued_on')->latest('id');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->latest('created_at')->latest('id');
    }

    /** The order's current (non-cancelled) agreement of kind `order`, if any. */
    public function currentAgreement(): ?Agreement
    {
        return $this->agreements()->where('kind', AgreementKind::Order)->where('status', '!=', AgreementStatus::Cancelled)->first();
    }

    /** Retail payment document issued (section 25): an issued sales receipt exists. */
    public function hasIssuedReceipt(): bool
    {
        return $this->invoices()->where('type', InvoiceType::SalesReceipt)->where('status', InvoiceStatus::Issued)->exists();
    }

    /**
     * Agreement step applies: B2B by default, retail only when one is required or exists.
     */
    public function needsAgreementStep(): bool
    {
        return $this->agreement_required || $this->currentAgreement() !== null || $this->framework_agreement_id !== null;
    }

    /** Agreement requirement satisfied: waived, own agreement binding, or an active framework agreement. */
    public function hasBindingAgreement(): bool
    {
        if (! $this->agreement_required) {
            return true;
        }

        if ($this->currentAgreement()?->status->isBinding()) {
            return true;
        }

        return $this->framework_agreement_id !== null
            && Agreement::query()->whereKey($this->framework_agreement_id)->where('status', AgreementStatus::Active)->exists();
    }

    public function money(?int $minor): ?Money
    {
        return $minor === null ? null : Money::of($minor, $this->currency);
    }

    /**
     * Active (not overridden) holds.
     *
     * @return list<HoldReason>
     */
    public function activeHolds(): array
    {
        return collect($this->hold_reasons ?? [])
            ->filter(fn (array $hold): bool => empty($hold['overridden_at']))
            ->map(fn (array $hold): ?HoldReason => HoldReason::tryFrom($hold['reason']))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Payment signal for the warehouse (Spec 06 section 29): paid, partly paid, not paid but
     * released before payment by a manager (prepayment hold overridden), credit terms, or not paid.
     *
     * @return array{state: string, paid_minor: int, released_by: string|null, reason: string|null}
     */
    public function paymentSignal(): array
    {
        $release = collect($this->hold_reasons ?? [])->first(fn (array $hold): bool => $hold['reason'] === HoldReason::PrepaymentMissing->value && ! empty($hold['overridden_at']));
        $state = match (true) {
            $this->payment_status === OrderPaymentStatus::Paid => 'paid',
            $release !== null => 'released',
            $this->paid_minor > 0 => 'partial',
            ! $this->sale_type->isRetail() && (int) $this->prepayment_bp === 0 => 'credit_terms',
            default => 'unpaid',
        };

        return [
            'state' => $state,
            'paid_minor' => (int) $this->paid_minor,
            'released_by' => $release !== null ? User::query()->whereKey($release['overridden_by'] ?? 0)->value('name') : null,
            'reason' => $release['override_reason'] ?? null,
        ];
    }

    /**
     * Orders the user may see (Spec 06 4.3 `orders.view`).
     *
     * @param  Builder<Order>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        PermissionMap::applyScope($query, $user, 'orders.view');
    }

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
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'paid_minor', 'invoiced_gross_minor', 'revision', 'on_hold', 'packed_at'];
    }
}
