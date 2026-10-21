<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Append-only allocation of a payment to an order (Spec 06 7.12). Deallocation = a reversal row
 * with negative amounts pointing at the original (`reverses_id`, unique).
 */
#[Fillable([
    'payment_id', 'order_id', 'invoice_id', 'amount_minor', 'order_amount_minor', 'fx_rate', 'fx_rate_date', 'fx_source',
    'reverses_id', 'reason', 'period', 'created_by', 'created_at',
])]
class PaymentAllocation extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Allocations are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Allocations are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'order_amount_minor' => 'integer',
            'fx_rate' => 'decimal:8',
            'fx_rate_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return HasOne<PaymentAllocation, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }
}
