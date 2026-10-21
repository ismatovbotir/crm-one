<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Documents\InteractsWithDocumentLanguage;
use App\Enums\CustomerOrigin;
use App\Enums\LegalType;
use App\Enums\SaleType;
use App\Enums\SyncStatus;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SyncsWithOneC;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\Inbound\OneCSyncContext;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Support\CustomerNameNormalizer;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Partner = one organization (Spec 03 section 24, table `partners`) with roles: customer, supplier,
 * carrier (`is_customer`, `is_supplier`, `is_carrier`). Shared with 1C as a customer when it has the
 * customer role. Documents for it print in its preferred language. `Customer` is the same table
 * restricted to the customer role; the supplier/carrier profile is `Counterparty`
 * (`counterparties.partner_id`).
 *
 * @property CustomerOrigin $origin
 * @property LegalType $legal_type
 * @property SyncStatus $sync_status
 */
#[Fillable([
    'origin', 'name', 'legal_name', 'legal_type', 'tax_id', 'country', 'city', 'address', 'postal_code', 'phone', 'email',
    'website', 'industry', 'currency', 'payment_terms_days', 'owner_id', 'preferred_locale', 'notes', 'sale_type',
    'sync_status', 'sync_error', 'has_duplicate_tax_id', 'external_id', 'external_code', 'synced_at',
    'vat_registration_code', 'oked', 'signatory_name', 'signatory_position', 'signatory_basis',
    'portal_enabled', 'portal_approval_threshold_minor', 'acquisition_source', 'acquisition_campaign', 'source_lead_id',
    'telegram_service_updates', 'sync_with_1c', 'industry_id', 'region_id', 'city_id',
    'needs_review', 'review_reason',
])]
class Partner extends Model implements HasDocumentLanguage
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids, InteractsWithDocumentLanguage, LogsActivity, SoftDeletes, SyncsWithOneC;

    /** Identity fields copied to the supplier/carrier profile (Spec 03 section 22.1). */
    public const IDENTITY_FIELDS = ['name', 'legal_name', 'tax_id', 'country', 'city', 'address', 'phone', 'email', 'website', 'preferred_locale'];

    protected static function booted(): void
    {
        static::saving(function (Partner $customer): void {
            // Spec 03 section 24.7: list values keep the readable text columns in step (uz names).
            if ($customer->isDirty('industry_id')) {
                $customer->industry = $customer->industry_id !== null ? ReferenceItem::query()->find($customer->industry_id)?->name('uz') : $customer->industry;
            } elseif ($customer->isDirty('industry') && filled($customer->industry) && $customer->industry_id === null) {
                $needle = mb_strtolower(trim((string) $customer->industry));
                $customer->industry_id = ReferenceItem::query()->where('list', 'industry')->get()->first(fn (ReferenceItem $item): bool => in_array($needle, array_map('mb_strtolower', array_values($item->names)), true))?->id;
            }

            if ($customer->isDirty('city_id') && $customer->city_id !== null) {
                $city = GeoPlace::query()->find($customer->city_id);
                $customer->city = $city?->name('uz') ?? $customer->city;
                $customer->region_id = $city?->parent_id ?? $customer->region_id;
            }

            if (strtoupper((string) $customer->country) !== 'UZ') {
                $customer->region_id = null;
                $customer->city_id = null;
            }

            $customer->name = trim((string) $customer->name);
            $customer->name_normalized = CustomerNameNormalizer::normalize($customer->name);
            $customer->tax_id = self::normalizeTaxId($customer->tax_id);
            $customer->email = filled($customer->email) ? mb_strtolower(trim($customer->email)) : null;
            $customer->phone = self::normalizePhone($customer->phone);
            $customer->currency = strtoupper((string) $customer->currency);
            $customer->country = strtoupper((string) $customer->country);
        });

        // Spec 02 rev. 3: CRM-side changes of shared fields go into the 1C outbox; changes applied
        // from 1C (OneCSyncContext) never re-queue.
        static::created(function (Partner $customer): void {
            if ($customer->is_customer && ! OneCSyncContext::active() && $customer->origin === CustomerOrigin::Crm && ! $customer->isLinkedToOneC()) {
                app(CustomerOutbox::class)->queue($customer, CustomerOutboxEntry::REASON_CREATED, auth()->user());
            }
        });

        // Spec 06 9.2: once the customer has a 1C GUID, its held order-feed outbox events go out.
        static::updated(function (Partner $customer): void {
            if ($customer->wasChanged('external_id') && filled($customer->external_id)) {
                app(OneCOutbox::class)->releaseCustomer($customer);
            }
        });

        static::updated(function (Partner $customer): void {
            if (! $customer->is_customer || OneCSyncContext::active()) {
                return;
            }

            // Spec 02 rev. 4: "Sync with 1C" switched on offers the partner; switched off withdraws open entries.
            if ($customer->wasChanged('sync_with_1c')) {
                $customer->sync_with_1c
                    ? app(CustomerOutbox::class)->queue(Customer::query()->findOrFail($customer->id), $customer->isLinkedToOneC() ? CustomerOutboxEntry::REASON_UPDATED : CustomerOutboxEntry::REASON_CREATED, auth()->user())
                    : app(CustomerOutbox::class)->supersedeOpen($customer, 'sync_off');

                return;
            }

            // Spec 03 section 22: an organization that just got the customer role is offered to 1C as new.
            if ($customer->wasChanged('is_customer')) {
                if ($customer->origin === CustomerOrigin::Crm && ! $customer->isLinkedToOneC()) {
                    app(CustomerOutbox::class)->queue(Customer::query()->findOrFail($customer->id), CustomerOutboxEntry::REASON_CREATED, auth()->user());
                }

                return;
            }

            if (array_intersect(array_keys($customer->getChanges()), CustomerOutbox::SHARED_FIELDS) !== []) {
                app(CustomerOutbox::class)->queue($customer, CustomerOutboxEntry::REASON_UPDATED, auth()->user());
            }
        });

        // Spec 03 section 22.1: the organization is the master of identity; the supplier profile keeps a copy.
        static::saved(function (Partner $customer): void {
            $changed = array_intersect(array_keys($customer->getChanges()), self::IDENTITY_FIELDS);

            if ($changed !== []) {
                Counterparty::withTrashed()->where('partner_id', $customer->id)->update($customer->only($changed));
            }
        });
    }

    /**
     * @return HasMany<CustomerOutboxEntry, $this>
     */
    public function outboxEntries(): HasMany
    {
        return $this->hasMany(CustomerOutboxEntry::class, 'customer_id');
    }

    protected $table = 'partners';

    public function getMorphClass(): string
    {
        return 'partner';
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    public function isSupplierOrCarrier(): bool
    {
        return (bool) $this->is_supplier || (bool) $this->is_carrier;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCustomers(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_customer'), true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSuppliers(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_supplier'), true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeCarriers(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_carrier'), true);
    }

    /**
     * Role values held by the partner, in display order.
     *
     * @return list<string> customer|supplier|carrier
     */
    public function roles(): array
    {
        return array_keys(array_filter(['customer' => (bool) $this->is_customer, 'supplier' => (bool) $this->is_supplier, 'carrier' => (bool) $this->is_carrier]));
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
    protected $attributes = ['sale_type' => 'b2b', 'is_customer' => true, 'is_supplier' => false, 'is_carrier' => false];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'origin' => CustomerOrigin::class,
            'portal_enabled' => 'boolean',
            'portal_approval_threshold_minor' => 'integer',
            'legal_type' => LegalType::class,
            'sale_type' => SaleType::class,
            'sync_status' => SyncStatus::class,
            'sync_queued_at' => 'datetime',
            'sync_error_fields' => 'array',
            'has_duplicate_tax_id' => 'boolean',
            'is_customer' => 'boolean',
            'is_supplier' => 'boolean',
            'is_carrier' => 'boolean',
            'payment_terms_days' => 'integer',
            'credit_limit_minor' => 'integer',
            'manual_hold' => 'boolean',
            'telegram_service_updates' => 'boolean',
            'sync_with_1c' => 'boolean',
            'needs_review' => 'boolean',
        ];
    }

    /**
     * Tax ID (INN): letters and digits only, uppercase; empty => null.
     */
    public static function normalizeTaxId(?string $taxId): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $taxId));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Phone: keep a leading "+" and digits only; empty => null.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $phone);

        return $digits === '' ? null : (str_starts_with($phone, '+') ? '+' : '').$digits;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<ReferenceItem, $this>
     */
    public function industryItem(): BelongsTo
    {
        return $this->belongsTo(ReferenceItem::class, 'industry_id');
    }

    /**
     * @return BelongsTo<GeoPlace, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(GeoPlace::class, 'region_id');
    }

    /**
     * @return BelongsTo<GeoPlace, $this>
     */
    public function cityPlace(): BelongsTo
    {
        return $this->belongsTo(GeoPlace::class, 'city_id');
    }

    /** Industry in the UI language (list entry), else the stored text. */
    public function industryLabel(): ?string
    {
        return $this->industryItem?->name() ?? $this->industry;
    }

    /** City in the UI language (UZ list entry), else the stored text. */
    public function cityLabel(): ?string
    {
        return $this->cityPlace?->name() ?? $this->city;
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'partner_id');
    }

    /**
     * @return HasOne<Contact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class, 'partner_id')->where('is_primary', true);
    }

    /**
     * @return HasMany<CustomerBankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CustomerBankAccount::class, 'customer_id')->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * Supplier/carrier profile of the organization (Spec 03 section 22), if it has that role.
     *
     * @return HasOne<Counterparty, $this>
     */
    public function supplierProfile(): HasOne
    {
        return $this->hasOne(Counterparty::class, 'partner_id')->orderBy('id');
    }

    /**
     * Role labels other than the customer role, for "Also a supplier / carrier" badges.
     *
     * @return list<string> supplier|carrier
     */
    public function otherRoles(): array
    {
        return array_keys(array_filter(['supplier' => (bool) $this->is_supplier, 'carrier' => (bool) $this->is_carrier]));
    }

    /**
     * Leads of this customer, including the one it was converted from (Spec 04 section 18).
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function sourceLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'source_lead_id');
    }

    /**
     * Spec 02 rev. 4: only partners with "Sync with 1C" on (and a tax ID) are offered to 1C.
     */
    public function syncsWithOneC(): bool
    {
        return (bool) $this->sync_with_1c && filled($this->tax_id);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /**
     * Customers the user may see (Spec 01 D-7 / config permissions `customers.view`).
     *
     * @param  Builder<Customer>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        PermissionMap::applyScope($query, $user, 'customers.view');
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        return $changedKeys === ['owner_id'] ? 'owner_changed' : 'updated';
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return [
            'uuid', 'name_normalized', 'crm_tax_key', 'sync_error_fields',
            'sync_attempts', 'external_hash', 'synced_at', 'external_updated_at',
        ];
    }
}
