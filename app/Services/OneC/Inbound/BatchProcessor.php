<?php

namespace App\Services\OneC\Inbound;

use App\Enums\InboundBatchStatus;
use App\Enums\OneCFeed;
use App\Models\Category;
use App\Models\InboundBatch;
use App\Models\InboundSnapshot;
use App\Models\IntegrationFeed;
use Illuminate\Support\Facades\DB;

/**
 * Applies a received 1C batch (Spec 02 7.3): sections in the fixed order units -> groups ->
 * items (with their sale/purchase prices) -> warehouses -> stock (with cost), 500 records per transaction, records
 * rejected at intake skipped, per-record outcomes counted. Then feed freshness, snapshot part
 * bookkeeping and, after the last part, the snapshot finalizer.
 */
class BatchProcessor
{
    public const CHUNK = 500;

    public function __construct(
        protected BatchValidator $validator,
        protected SnapshotFinalizer $finalizer,
    ) {}

    public function process(InboundBatch $batch): ProcessingResult
    {
        $batch->forceFill(['status' => InboundBatchStatus::Processing, 'processing_started_at' => now()])->save();

        $payload = new InboundPayload($batch->endpoint, $batch->decodedPayload() ?? []);
        $snapshotId = $batch->snapshot_id;
        $skip = $this->rejectedIndex($batch);
        $result = new ProcessingResult;

        OneCSyncContext::run(function () use ($payload, $snapshotId, $skip, $result): void {
            $this->processUnitsAndGroups($payload, $snapshotId, $skip, $result);
            $this->processSimple('items', new ItemUpserter, $payload, $snapshotId, $skip, $result);
            $this->processSimple('warehouses', new WarehouseUpserter, $payload, $snapshotId, $skip, $result);
            $this->processStock($payload, $snapshotId, $skip, $result);
        });

        $accepted = (int) $batch->accepted_count;
        $status = match (true) {
            $accepted > 0 && $result->counts['failed'] >= $accepted => InboundBatchStatus::Failed,
            $result->counts['failed'] > 0 || $batch->rejected_count > 0 => InboundBatchStatus::Partial,
            default => InboundBatchStatus::Processed,
        };

        if ($status !== InboundBatchStatus::Failed) {
            foreach ($payload->feeds() as $feed) {
                IntegrationFeed::markReceived($feed, $batch->batch_id);
            }
        }

        $this->save($batch, $status, $result);

        if ($snapshotId !== null) {
            $this->recordSnapshotPart($batch, $payload, $result);
        }

        return $result;
    }

    /**
     * Units first, then groups in two passes (all upserted, then parents linked).
     *
     * @param  array<string, true>  $skip
     */
    protected function processUnitsAndGroups(InboundPayload $payload, ?string $snapshotId, array $skip, ProcessingResult $result): void
    {
        $this->processSimple('units', new UnitUpserter, $payload, $snapshotId, $skip, $result);

        $groups = new GroupUpserter;
        $linked = [];

        $this->eachRecord('groups', $payload, $skip, $result, function (array $record, int $index) use ($groups, $snapshotId, $result, &$linked): void {
            $category = $groups->upsert($record, $index, $result, $snapshotId);

            if ($category instanceof Category) {
                $linked[] = [$category, $record, $index];
            }
        });
        $groups->flushSeen($snapshotId);

        foreach (array_chunk($linked, self::CHUNK) as $chunk) {
            DB::transaction(function () use ($chunk, $groups, $result): void {
                foreach ($chunk as [$category, $record, $index]) {
                    $groups->linkParent($category, $record, $index, $result);
                }
            });
        }
    }

    /**
     * @param  array<string, true>  $skip
     */
    protected function processSimple(string $section, CatalogUpserter $upserter, InboundPayload $payload, ?string $snapshotId, array $skip, ProcessingResult $result): void
    {
        $this->eachRecord($section, $payload, $skip, $result, fn (array $record, int $index) => $upserter->upsert($record, $index, $result, $snapshotId));
        $upserter->flushSeen($snapshotId);
    }

    /**
     * @param  array<string, true>  $skip
     */
    protected function processStock(InboundPayload $payload, ?string $snapshotId, array $skip, ProcessingResult $result): void
    {
        if ($payload->records('stock') === [] && $payload->records('warehouses') === []) {
            return;
        }

        $asOf = BatchValidator::parseDateTime((string) $payload->asOf()) ?? now()->toImmutable();
        $stock = new StockUpserter;

        foreach ($payload->records('warehouses') as $warehouse) {
            if (is_array($warehouse) && is_string($warehouse['id'] ?? null)) {
                $result->warehouseIds[strtolower($warehouse['id'])] = true;
            }
        }

        $this->eachRecord('stock', $payload, $skip, $result, fn (array $record, int $index) => $stock->upsert($record, $index, $asOf, $result, $snapshotId));
        $stock->flushSeen($snapshotId);
    }

    /**
     * Runs $callback for every accepted record of a section, in transactions of CHUNK records.
     *
     * @param  array<string, true>  $skip
     * @param  callable(array<string, mixed>, int): mixed  $callback
     */
    protected function eachRecord(string $section, InboundPayload $payload, array $skip, ProcessingResult $result, callable $callback): void
    {
        $records = $payload->records($section);

        if ($records !== []) {
            $result->sectionsSeen[$section] = true;
        }

        foreach (array_chunk($records, self::CHUNK, preserve_keys: true) as $chunk) {
            DB::transaction(function () use ($chunk, $section, $skip, $callback): void {
                foreach ($chunk as $index => $record) {
                    if (! isset($skip[$section.':'.$index]) && is_array($record)) {
                        $callback($record, (int) $index);
                    }
                }
            });
        }
    }

    /**
     * Records rejected at intake, keyed "section:index".
     *
     * @return array<string, true>
     */
    protected function rejectedIndex(InboundBatch $batch): array
    {
        $index = [];

        foreach ($batch->rejected ?? [] as $entry) {
            $index[$entry['section'].':'.$entry['index']] = true;
        }

        return $index;
    }

    protected function save(InboundBatch $batch, InboundBatchStatus $status, ProcessingResult $result): void
    {
        $batch->forceFill([
            'status' => $status,
            'counts' => [...$result->counts, 'rejected' => (int) $batch->rejected_count],
            'errors' => $result->errors,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Records that this part was processed and what it saw; finalizes after the last part.
     */
    protected function recordSnapshotPart(InboundBatch $batch, InboundPayload $payload, ProcessingResult $result): void
    {
        $snapshot = DB::transaction(function () use ($batch, $payload, $result): ?InboundSnapshot {
            $snapshot = InboundSnapshot::query()->lockForUpdate()->find($batch->snapshot_id);

            if ($snapshot === null || $snapshot->status !== InboundSnapshot::COLLECTING) {
                return null;
            }

            $seen = $snapshot->result ?? [];
            $seen['feeds'] = array_values(array_unique([...($seen['feeds'] ?? []), ...array_map(fn (OneCFeed $feed): string => $feed->value, $payload->feeds())]));
            $seen['sections'] = array_values(array_unique([...($seen['sections'] ?? []), ...array_keys($result->sectionsSeen)]));
            $seen['warehouse_ids'] = array_values(array_unique([...($seen['warehouse_ids'] ?? []), ...array_keys($result->warehouseIds)]));

            $snapshot->forceFill([
                'parts_processed' => array_values(array_unique([...($snapshot->parts_processed ?? []), (int) $batch->snapshot_part])),
                'result' => $seen,
            ])->save();

            return $snapshot;
        });

        if ($snapshot !== null && $snapshot->isComplete()) {
            $deactivated = $this->finalizer->finalize($snapshot);

            if ($deactivated > 0) {
                $counts = $batch->counts ?? [];
                $counts['deactivated'] = ($counts['deactivated'] ?? 0) + $deactivated;
                $batch->forceFill(['counts' => $counts])->save();
            }
        }
    }
}
