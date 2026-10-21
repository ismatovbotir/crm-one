<?php

namespace App\Models;

use App\Enums\EntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only ledger row (Spec 06 7.13, DEC-4): signed minor units in `currency`.
 *
 * @property EntryType $entry_type
 */
#[Fillable([
    'entry_type', 'customer_id', 'order_id', 'invoice_id', 'payment_id', 'allocation_id', 'amount_minor', 'currency',
    'occurred_on', 'period', 'source', 'causer_id', 'reverses_entry_id', 'meta', 'created_at',
])]
class AccountingEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Ledger entries are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Ledger entries are append-only.'));
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
            'entry_type' => EntryType::class,
            'amount_minor' => 'integer',
            'occurred_on' => 'immutable_date',
            'meta' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }
}
