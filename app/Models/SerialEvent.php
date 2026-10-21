<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of a serial number (Spec 06 21.5): every mutation with document and causer.
 */
#[Fillable([
    'item_serial_id', 'event', 'from_status', 'to_status', 'warehouse_from_id', 'warehouse_to_id', 'document_type', 'document_id',
    'line_id', 'customer_id', 'reason', 'causer_id', 'import_batch_id', 'created_at',
])]
class SerialEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Serial events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Serial events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * @return BelongsTo<ItemSerial, $this>
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class, 'item_serial_id');
    }
}
