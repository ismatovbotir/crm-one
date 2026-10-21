<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Enums\PurchaseOrderStatus;
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
use LogicException;

/**
 * Purchase order to a supplier (purchase-tender skill section 6; Spec 08 6.1/10.1). Created from
 * an accepted purchase award with a snapshot of the winning bid (one per award); the 1C document
 * number is entered manually in Phase 1 (1C pull-only). Never deleted.
 *
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable|null $required_delivery_date
 */
#[Fillable([
    'number', 'source', 'tender_award_id', 'supplier_id', 'supplier_order_ref', 'order_id', 'owner_id', 'department_id', 'status',
    'currency', 'subtotal_minor', 'incoterm', 'incoterm_place', 'pickup_country', 'pickup_city', 'pickup_address',
    'destination_warehouse_id', 'readiness_date', 'required_delivery_date', 'payment_terms', 'documents_available',
    'special_handling', 'payment_days', 'prepayment_bp', 'onec_number',
])]
class PurchaseOrder extends Model
{
    use HasUuids, LogsActivity;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Purchase orders are never deleted; cancel instead.'));
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
            'status' => PurchaseOrderStatus::class,
            'subtotal_minor' => 'integer',
            'payment_days' => 'integer',
            'prepayment_bp' => 'integer',
            'documents_available' => 'array',
            'readiness_date' => 'immutable_date',
            'required_delivery_date' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class, 'supplier_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TenderAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(TenderAward::class, 'tender_award_id');
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
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return MorphMany<StatusTransition, $this>
     */
    public function transitions(): MorphMany
    {
        return $this->morphMany(StatusTransition::class, 'subject')->orderBy('id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Incoterms 2020 that leave a transport leg to the buyer (Spec 08 BR-PO.4): main carriage
     * (EXW/FCA/FAS/FOB) or on-carriage from the named place (CPT/CIP/CFR/CIF).
     */
    public function buyerHasTransportLeg(): bool
    {
        return in_array($this->incoterm, ['EXW', 'FCA', 'FAS', 'FOB', 'CPT', 'CIP', 'CFR', 'CIF'], true);
    }

    public function money(int $minor): Money
    {
        return Money::of($minor, $this->currency);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        PermissionMap::applyScope($query, $user, 'purchase_orders.view');
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
