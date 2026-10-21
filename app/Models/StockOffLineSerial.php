<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specific serial picked on a stock-off act line (Spec 06 27.2). `active_key` holds the serial
 * while the act is open, so one serial is never on two open acts.
 */
#[Fillable(['line_id', 'item_serial_id', 'serial_number', 'active_key', 'created_at'])]
class StockOffLineSerial extends Model
{
    public const UPDATED_AT = null;

    public static function activeKey(int $itemSerialId): string
    {
        return 'serial:'.$itemSerialId;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<StockOffLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StockOffLine::class, 'line_id');
    }

    /**
     * @return BelongsTo<ItemSerial, $this>
     */
    public function itemSerial(): BelongsTo
    {
        return $this->belongsTo(ItemSerial::class);
    }
}
