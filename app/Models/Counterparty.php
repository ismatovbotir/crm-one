<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Documents\InteractsWithDocumentLanguage;
use App\Enums\CounterpartyStatus;
use App\Models\Concerns\LogsActivity;
use Database\Factories\CounterpartyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Supplier and/or carrier (Spec 03 7.2, DEC-12): invited to tenders, qualified through documents
 * with expiry, rated after deliveries. Separate from customers. Tender notices print in its
 * preferred language.
 *
 * @property CounterpartyStatus $status
 */
#[Fillable([
    'name', 'legal_name', 'tax_id', 'country', 'city', 'address', 'phone', 'email', 'website', 'is_supplier', 'is_carrier',
    'status', 'status_reason', 'fleet_size', 'vehicle_types', 'lanes', 'has_tir', 'has_adr', 'preferred_locale', 'owner_id',
    'department_id', 'notes', 'supplier_category_id',
])]
class Counterparty extends Model implements HasDocumentLanguage
{
    /** @use HasFactory<CounterpartyFactory> */
    use HasFactory, HasUuids, InteractsWithDocumentLanguage, LogsActivity, SoftDeletes;

    /**
     * Spec 03 section 22: a counterparty is the supplier/carrier profile of an organization (1:1).
     * The organization is the master of identity (name, tax ID, address, contacts); the identity
     * columns here are a maintained copy. A new profile without an organization creates one
     * (supplier/carrier roles only). Identity edits made here reach the organization unless it is
     * also a customer (then identity is edited on the customer card).
     */
    protected static function booted(): void
    {
        static::creating(function (Counterparty $counterparty): void {
            if ($counterparty->partner_id !== null) {
                return;
            }

            $organization = new Partner([
                ...$counterparty->only(Customer::IDENTITY_FIELDS),
                'origin' => 'crm',
                'country' => $counterparty->country ?: (string) config('crm.default_country', 'UZ'),
                'currency' => (string) config('crm.base_currency', 'UZS'),
                'legal_type' => 'legal_entity',
                'owner_id' => $counterparty->owner_id ?? auth()->id() ?? User::query()->orderBy('id')->value('id'),
                'notes' => $counterparty->notes,
                'sync_status' => 'not_synced',
            ]);
            $organization->forceFill(['is_customer' => false, 'is_supplier' => (bool) $counterparty->is_supplier, 'is_carrier' => (bool) $counterparty->is_carrier])->save();
            $counterparty->partner_id = $organization->id;
            $counterparty->forceFill($organization->only(Customer::IDENTITY_FIELDS));
        });

        static::saved(function (Counterparty $counterparty): void {
            $organization = Partner::withTrashed()->find($counterparty->partner_id);

            if ($organization === null) {
                return;
            }

            $changes = $counterparty->wasRecentlyCreated ? [] : array_intersect(array_keys($counterparty->getChanges()), Customer::IDENTITY_FIELDS);

            if ($changes !== [] && ! $organization->is_customer) {
                $organization->fill($counterparty->only($changes));
            }

            $organization->forceFill([
                'is_supplier' => ! $counterparty->trashed() && (bool) $counterparty->is_supplier,
                'is_carrier' => ! $counterparty->trashed() && (bool) $counterparty->is_carrier,
            ]);

            if ($organization->isDirty()) {
                $organization->save();
            }
        });

        static::deleted(function (Counterparty $counterparty): void {
            Partner::withTrashed()->whereKey($counterparty->partner_id)->update(['is_supplier' => false, 'is_carrier' => false]);
        });

        static::restored(function (Counterparty $counterparty): void {
            Partner::withTrashed()->whereKey($counterparty->partner_id)->update(['is_supplier' => (bool) $counterparty->is_supplier, 'is_carrier' => (bool) $counterparty->is_carrier]);
        });
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id')->withTrashed();
    }

    /**
     * Identity fields are edited on the customer card when the organization is also a customer
     * (customer data, possibly 1C-synced, stays with sales; Spec 03 section 22.4).
     */
    public function identityLocked(): bool
    {
        return $this->organization !== null && (bool) $this->organization->is_customer;
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
            'status' => CounterpartyStatus::class,
            'is_supplier' => 'boolean',
            'is_carrier' => 'boolean',
            'has_tir' => 'boolean',
            'has_adr' => 'boolean',
            'vehicle_types' => 'array',
            'lanes' => 'array',
            'rating' => 'decimal:2',
            'fleet_size' => 'integer',
            'qualified_at' => 'immutable_datetime',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'partner_id', 'partner_id')->orderByDesc('is_primary')->orderBy('first_name')->orderBy('last_name');
    }

    /**
     * @return HasMany<CounterpartyDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CounterpartyDocument::class)->orderBy('type')->orderBy('id');
    }

    /**
     * @return HasMany<CounterpartyRating, $this>
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(CounterpartyRating::class)->latest('created_at')->latest('id');
    }

    /**
     * @return HasMany<TenderInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TenderInvitation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function primaryContact(): ?Contact
    {
        return $this->contacts->first();
    }

    /**
     * @param  Builder<Counterparty>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! PermissionMap::allows($user, 'counterparties.view')) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid'];
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        return in_array('status', $changedKeys, true) ? 'status_changed' : 'updated';
    }
}
