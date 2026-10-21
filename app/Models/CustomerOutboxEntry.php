<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "please (re)send this customer to 1C" request (Spec 02 rev. 3, BR-I.7). 1C polls open
 * entries (not acknowledged, not superseded) and acknowledges them; the id is the poll cursor.
 *
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $first_delivered_at
 * @property CarbonImmutable|null $last_delivered_at
 * @property CarbonImmutable|null $acked_at
 * @property CarbonImmutable|null $superseded_at
 * @property array<string, mixed>|null $ack_error
 */
#[Fillable([
    'customer_id', 'reason', 'queued_by', 'queued_at', 'first_delivered_at', 'last_delivered_at', 'delivery_count',
    'acked_at', 'ack_status', 'ack_external_id', 'ack_error', 'superseded_at', 'superseded_reason',
])]
class CustomerOutboxEntry extends Model
{
    public const REASON_CREATED = 'created';

    public const REASON_UPDATED = 'updated';

    public const REASON_CONTACTS = 'contacts';

    public const REASON_RESYNC = 'resync';

    public const SUPERSEDED_EDITED = 'edited_after_delivery';

    public const SUPERSEDED_BY_1C = 'overwritten_by_1c';

    protected $table = 'customer_outbox';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'queued_at' => 'immutable_datetime',
            'first_delivered_at' => 'immutable_datetime',
            'last_delivered_at' => 'immutable_datetime',
            'acked_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'ack_error' => 'array',
            'delivery_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @param  Builder<CustomerOutboxEntry>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('acked_at')->whereNull('superseded_at');
    }

    public function isOpen(): bool
    {
        return $this->acked_at === null && $this->superseded_at === null;
    }

    public function wasDelivered(): bool
    {
        return $this->first_delivered_at !== null;
    }
}
