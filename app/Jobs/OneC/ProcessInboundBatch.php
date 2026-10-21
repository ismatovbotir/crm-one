<?php

namespace App\Jobs\OneC;

use App\Enums\InboundBatchStatus;
use App\Models\InboundBatch;
use App\Services\OneC\Inbound\BatchProcessor;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Processes one received 1C batch (Spec 02 7.3). Batches run one at a time in arrival order:
 * a single FIFO queue (`onec-inbound`) plus a shared WithoutOverlapping lock. The payload stays
 * in the database; the job carries only the batch id. Upserts are idempotent, so a retry after
 * a crash is safe. Works on the database queue and synchronously (QUEUE_CONNECTION=sync).
 */
class ProcessInboundBatch implements ShouldQueue
{
    use Queueable;

    /** Keep below the database queue's retry_after (360 s). */
    public int $timeout = 300;

    /** Real failures allowed; lock waits (releases) do not count. */
    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $inboundBatchId)
    {
        $this->onQueue((string) config('onec.inbound.queue'));
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('onec-inbound'))->releaseAfter(5)->expireAfter($this->timeout + 60)];
    }

    public function handle(BatchProcessor $processor): void
    {
        $batch = InboundBatch::query()->find($this->inboundBatchId);

        if ($batch === null || ! in_array($batch->status, [InboundBatchStatus::Queued, InboundBatchStatus::Processing], true)) {
            return;
        }

        $processor->process($batch);
    }

    public function failed(?Throwable $exception): void
    {
        InboundBatch::query()->whereKey($this->inboundBatchId)->update([
            'status' => InboundBatchStatus::Failed->value,
            'errors' => json_encode([['section' => null, 'index' => null, 'id' => null, 'code' => 'internal_error', 'message' => 'processing failed; see the application log', 'level' => 'error']]),
            'processed_at' => now(),
        ]);
    }
}
