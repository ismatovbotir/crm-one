<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One CRM → 1C event in the transactional outbox (Spec 06 7.15/9.2). Written by
 * App\Services\OneC\Outbox\OneCOutbox in the same transaction as the business change.
 *
 * @property OutboxStatus $status
 * @property array<string, mixed> $payload
 */
#[Fillable([
    'event_id', 'event_type', 'aggregate_type', 'aggregate_id', 'aggregate_uuid', 'aggregate_version', 'customer_id', 'feed',
    'payload', 'payload_hash', 'status', 'occurred_at', 'created_at',
])]
class OnecOutboxEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $table = 'onec_outbox';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OutboxStatus::class,
            'payload' => 'array',
            'ack_payload' => 'array',
            'aggregate_version' => 'integer',
            'delivered_count' => 'integer',
            'first_delivered_at' => 'immutable_datetime',
            'last_delivered_at' => 'immutable_datetime',
            'acked_at' => 'immutable_datetime',
            'posting_updated_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Pruned 180 days after the ack (Spec 06 7.15).
     *
     * @return Builder<OnecOutboxEvent>
     */
    public function prunable(): Builder
    {
        return static::query()->whereIn('status', [OutboxStatus::Acked, OutboxStatus::Superseded])->where('acked_at', '<', now()->subDays(180));
    }
}
