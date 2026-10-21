<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Enums\AssetSource;
use App\Enums\AssetStatus;
use App\Enums\DataScope;
use App\Enums\ServiceTicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit in a customer's equipment register (Spec 09): keyed by serial number, linked to the sold
 * serial of our registry when we sold it (`item_serial_id`, source of truth of ownership), else
 * registered by the customer or staff (unverified until confirmed). Holds the customer-facing
 * status, the "in storage" flag, location and warranty.
 *
 * @property AssetStatus $status
 * @property AssetSource $source
 * @property CarbonImmutable|null $warranty_until
 */
#[Fillable(['partner_id', 'item_serial_id', 'item_id', 'model_name', 'serial_raw', 'serial_normalized', 'source', 'status', 'location', 'purchased_on', 'sold_at', 'order_id', 'delivery_id', 'warranty_until', 'photo_path', 'notes', 'created_by'])]
class CustomerAsset extends Model
{
    use HasUuids;

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
            'status' => AssetStatus::class,
            'source' => AssetSource::class,
            'purchased_on' => 'immutable_date',
            'sold_at' => 'immutable_datetime',
            'warranty_until' => 'immutable_date',
            'verified_at' => 'immutable_datetime',
            'warranty_reminded_at' => 'immutable_datetime',
            'disputed_at' => 'immutable_datetime',
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
     * @return BelongsTo<ItemSerial, $this>
     */
    public function itemSerial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return HasMany<AssetEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(AssetEvent::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<ServiceTicket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class)->orderByDesc('id');
    }

    public function openTicket(): ?ServiceTicket
    {
        return $this->tickets()->whereIn('status', ServiceTicketStatus::openValues())->first();
    }

    public function displayName(): string
    {
        return (string) ($this->item?->name ?? $this->model_name ?? '—');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function underWarranty(?CarbonImmutable $on = null): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->endOfDay()->gte($on ?? now()->toImmutable());
    }

    /**
     * @param  Builder<CustomerAsset>  $query
     */
    public function scopeForOrganization(Builder $query, int $organizationId): void
    {
        $query->where('partner_id', $organizationId);
    }
}
