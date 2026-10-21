<?php

namespace App\Models;

use App\Enums\SerialSource;
use App\Enums\SerialStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One serial number of an item (Spec 06 21.5, DEC-17): CRM-master registry. Status changes go
 * through the stock Actions (claim, post, return) and are logged in `serial_events`; never deleted.
 *
 * @property SerialStatus $status
 * @property SerialSource $source
 * @property CarbonImmutable|null $warranty_until
 */
#[Fillable([
    'item_id', 'serial_raw', 'serial_normalized', 'global_key', 'status', 'warehouse_id', 'source', 'received_receipt_line_id',
    'received_at', 'reserved_by_line_type', 'reserved_by_line_id', 'reserved_at', 'sold_delivery_line_id', 'sold_order_line_id',
    'customer_id', 'sold_at', 'warranty_until',
])]
class ItemSerial extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Serial numbers are never deleted.'));
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
            'status' => SerialStatus::class,
            'source' => SerialSource::class,
            'received_at' => 'immutable_datetime',
            'reserved_at' => 'immutable_datetime',
            'sold_at' => 'immutable_datetime',
            'warranty_until' => 'immutable_date',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function soldOrderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'sold_order_line_id');
    }

    /**
     * @return BelongsTo<DeliveryLine, $this>
     */
    public function soldDeliveryLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryLine::class, 'sold_delivery_line_id');
    }

    /**
     * @return BelongsTo<GoodsReceiptLine, $this>
     */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'received_receipt_line_id');
    }

    /**
     * @return HasMany<SerialEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SerialEvent::class)->orderBy('id');
    }
}
