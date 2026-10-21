<?php

namespace App\Models;

use App\Enums\InboundBatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Raw JSON body of a 1C batch, kept apart from the batch row (DB review S1). Pruned after
 * onec.inbound.batch_retention_days (rejected batches after rejected_retention_days); the batch
 * row with its hash, counts and stored response stays for idempotent replay and audit.
 */
#[Fillable(['inbound_batch_id', 'payload', 'created_at'])]
class InboundBatchPayload extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $primaryKey = 'inbound_batch_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<InboundBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(InboundBatch::class, 'inbound_batch_id');
    }

    /**
     * @return Builder<InboundBatchPayload>
     */
    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $query): void {
            $query->where('created_at', '<', now()->subDays((int) config('onec.inbound.batch_retention_days')))
                ->orWhere(fn (Builder $rejected) => $rejected
                    ->where('created_at', '<', now()->subDays((int) config('onec.inbound.rejected_retention_days')))
                    ->whereIn('inbound_batch_id', InboundBatch::query()->select('id')->where('status', InboundBatchStatus::Rejected)));
        });
    }
}
