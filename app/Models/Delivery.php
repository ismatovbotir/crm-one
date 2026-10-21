<?php

namespace App\Models;

use App\Authorization\PermissionMap;
use App\Documents\HasDocumentLanguage;
use App\Enums\Locale;
use App\Enums\StockDocumentStatus;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Delivery note = sales document (Spec 06 7.6, DEC-16): drafted from an order, serial numbers
 * registered per unit by the stock manager, posting sells the serials and adds to the order's
 * delivered quantities. Posted notes are immutable; never deleted.
 *
 * @property StockDocumentStatus $status
 * @property CarbonImmutable|null $delivered_on
 */
#[Fillable(['number', 'order_id', 'status', 'warehouse_id', 'planned_on', 'delivered_on', 'shipment_id', 'created_by', 'note', 'pickup_person', 'pickup_document'])]
class Delivery extends Model implements HasDocumentLanguage
{
    use HasUuids, LogsActivity;

    public const DOCUMENT_TYPE = 'delivery_note';

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Delivery notes are never deleted; cancel the draft.'));
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
            'status' => StockDocumentStatus::class,
            'planned_on' => 'immutable_date',
            'delivered_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'packed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'onec_acked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<DeliveryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Packing list complete (Spec 06 section 25): locked for edits, ready to be posted as delivery.
     */
    public function isPacked(): bool
    {
        return $this->packed_at !== null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function packer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Delivery notes of orders the user may see, or all for stock and logistics roles.
     *
     * @param  Builder<Delivery>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (PermissionMap::allows($user, 'stock.view')) {
            return;
        }

        $query->whereIn($query->qualifyColumn('order_id'), Order::query()->visibleTo($user)->select('id'));
    }

    public function preferredDocumentLocale(): ?Locale
    {
        return $this->order?->preferredDocumentLocale();
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['uuid', 'packed_at', 'packed_by'];
    }
}
