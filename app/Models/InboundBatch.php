<?php

namespace App\Models;

use App\Enums\InboundBatchStatus;
use App\Enums\SyncMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One request pushed by 1C (Spec 02 section 11.4): raw payload for audit/replay, intake response
 * for idempotent replays, processing counters and per-record errors. The raw payload lives in
 * inbound_batch_payloads (pruned after 30/7 days); the row itself is kept a year.
 *
 * @property InboundBatchStatus $status
 * @property SyncMode|null $mode
 * @property array<string, mixed> $response_body
 * @property array<string, int>|null $counts
 * @property list<array<string, mixed>>|null $rejected
 * @property list<array<string, mixed>>|null $errors
 */
#[Fillable([
    'batch_id', 'integration_token_id', 'endpoint', 'mode', 'snapshot_id', 'snapshot_part', 'snapshot_total_parts',
    'status', 'payload_hash', 'record_count', 'accepted_count', 'rejected_count', 'counts', 'rejected',
    'errors', 'response_status', 'response_body', 'source', 'ip', 'request_id', 'received_at',
    'processing_started_at', 'processed_at', 'reprocessed_by',
])]
class InboundBatch extends Model
{
    use MassPrunable;

    /** Most processing errors kept on the batch (Spec 02 7.3). */
    public const MAX_ERRORS = 1000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InboundBatchStatus::class,
            'mode' => SyncMode::class,
            'counts' => 'array',
            'rejected' => 'array',
            'errors' => 'array',
            'response_body' => 'array',
            'source' => 'array',
            'received_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<IntegrationToken, $this>
     */
    public function token(): BelongsTo
    {
        return $this->belongsTo(IntegrationToken::class, 'integration_token_id');
    }

    /**
     * Payload decoded as an array (null when pruned or not JSON).
     *
     * @return array<string, mixed>|null
     */
    public function decodedPayload(): ?array
    {
        $decoded = json_decode((string) $this->payloadRow?->payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Raw body (DB review S1): separate table, pruned on its own.
     *
     * @return HasOne<InboundBatchPayload, $this>
     */
    public function payloadRow(): HasOne
    {
        return $this->hasOne(InboundBatchPayload::class, 'inbound_batch_id');
    }

    /**
     * Batch rows (hash, counts, stored response) are kept a year for audit and idempotent replay;
     * the raw payload is pruned much earlier (InboundBatchPayload, DB review F-04).
     *
     * @return Builder<InboundBatch>
     */
    public function prunable(): Builder
    {
        return static::query()->where('received_at', '<', now()->subDays((int) config('onec.inbound.batch_metadata_retention_days', 365)));
    }
}
