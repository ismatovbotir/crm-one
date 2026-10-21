<?php

namespace App\Actions\OneC;

use App\Enums\InboundBatchStatus;
use App\Enums\SyncMode;
use App\Jobs\OneC\ProcessInboundBatch;
use App\Models\InboundBatch;
use App\Models\InboundBatchPayload;
use App\Models\InboundSnapshot;
use App\Models\IntegrationToken;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\Inbound\InboundPayload;
use App\Support\IntegrationApi;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Synchronous intake of a 1C batch (Spec 02 7.2): parse, idempotency, envelope and record
 * validation, snapshot bookkeeping, persistence of the raw batch and its response, then the
 * queued processing job (after commit). 1C never waits for upserts.
 */
class ReceiveInboundBatch
{
    public function __construct(protected BatchValidator $validator) {}

    public function handle(Request $request, IntegrationToken $token, string $endpoint): JsonResponse
    {
        $raw = $this->stripBom($request->getContent());
        $hash = hash('sha256', $raw);
        $data = json_decode($raw, true);

        if (! is_array($data) || array_is_list($data)) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The body must be a JSON object.', ['body' => ['invalid_json']]);
        }

        $payload = new InboundPayload($endpoint, $data);
        $batchId = is_string($data['batch_id'] ?? null) && preg_match(BatchValidator::GUID_PATTERN, $data['batch_id']) === 1
            ? strtolower($data['batch_id'])
            : null;

        $idempotencyKey = $request->headers->get('Idempotency-Key');

        if ($idempotencyKey !== null && strtolower(trim($idempotencyKey)) !== $batchId) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'Idempotency-Key must equal batch_id.', ['Idempotency-Key' => ['must_equal_batch_id']]);
        }

        if ($batchId !== null) {
            $request->attributes->set(IntegrationApi::BATCH_ATTRIBUTE, $batchId);

            if ($replay = $this->replay($request, $batchId, $hash)) {
                return $replay;
            }
        }

        $envelopeErrors = $this->validator->envelopeErrors($payload);

        if ($envelopeErrors !== []) {
            $response = $this->errorBody($request, 'validation_failed', 'The batch envelope is invalid.', $envelopeErrors);

            return $this->storeRejected($request, $token, $endpoint, $payload, $batchId, $hash, $raw, $response, 422);
        }

        $mode = SyncMode::from($data['mode']);
        $snapshot = $mode === SyncMode::Full ? $data['snapshot'] : null;

        if ($snapshot !== null && ($conflict = $this->snapshotConflict($request, $endpoint, $snapshot))) {
            return $conflict;
        }

        $rejected = $this->validator->rejectedRecords($payload);
        $recordCount = $payload->recordCount();
        $accepted = $recordCount - count($rejected);

        if ($recordCount > 0 && $accepted === 0) {
            $response = $this->errorBody($request, 'validation_failed', 'Every record in the batch is invalid.', ['records' => ['all_invalid']]);
            $response['error']['rejected'] = $rejected;

            return $this->storeRejected($request, $token, $endpoint, $payload, $batchId, $hash, $raw, $response, 422);
        }

        $status = $rejected === [] ? 202 : 207;
        $body = [
            'batch_id' => $batchId,
            'status' => InboundBatchStatus::Queued->value,
            'received' => $payload->receivedCounts(),
            'accepted' => $accepted,
            'rejected' => $rejected,
            'status_url' => '/api/v1/integration/1c/batches/'.$batchId,
            'request_id' => IntegrationApi::requestId($request),
        ];

        try {
            $batch = DB::transaction(function () use ($request, $token, $endpoint, $payload, $batchId, $hash, $raw, $mode, $snapshot, $recordCount, $accepted, $rejected, $status, $body): InboundBatch {
                if ($snapshot !== null) {
                    InboundSnapshot::query()->firstOrCreate(['id' => strtolower($snapshot['id'])], [
                        'endpoint' => $endpoint,
                        'scope' => $snapshot['scope'] ?? null,
                        'total_parts' => $snapshot['total_parts'],
                        'parts_processed' => [],
                        'status' => InboundSnapshot::COLLECTING,
                        'started_at' => now(),
                    ]);
                }

                $batch = InboundBatch::query()->create([
                    ...$this->baseAttributes($request, $token, $endpoint, $payload, $batchId, $hash, $raw),
                    'mode' => $mode,
                    'snapshot_id' => $snapshot !== null ? strtolower($snapshot['id']) : null,
                    'snapshot_part' => $snapshot['part'] ?? null,
                    'snapshot_total_parts' => $snapshot['total_parts'] ?? null,
                    'status' => InboundBatchStatus::Queued,
                    'record_count' => $recordCount,
                    'accepted_count' => $accepted,
                    'rejected_count' => count($rejected),
                    'rejected' => $rejected,
                    'response_status' => $status,
                    'response_body' => $body,
                ]);

                InboundBatchPayload::query()->create(['inbound_batch_id' => $batch->id, 'payload' => $raw, 'created_at' => now()]);
                ProcessInboundBatch::dispatch($batch->id)->afterCommit();

                return $batch;
            });
        } catch (QueryException $exception) {
            // A concurrent request stored the same batch_id first: answer as a replay.
            if ($batchId !== null && ($replay = $this->replay($request, $batchId, $hash))) {
                return $replay;
            }

            throw $exception;
        }

        return response()->json($batch->response_body, $status);
    }

    protected function replay(Request $request, string $batchId, string $hash): ?JsonResponse
    {
        $existing = InboundBatch::query()->where('batch_id', $batchId)->first();

        if ($existing === null) {
            return null;
        }

        if (! hash_equals($existing->payload_hash, $hash)) {
            return IntegrationApi::error($request, 409, 'idempotency_key_reused', 'This batch_id was already used with a different body.');
        }

        if ($existing->integration_token_id !== IntegrationApi::token($request)?->id) {
            // Same body from another token: do not disclose the stored response.
            return IntegrationApi::error($request, 409, 'idempotency_key_reused', 'This batch_id was already used.');
        }

        return response()->json($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function snapshotConflict(Request $request, string $endpoint, array $snapshot): ?JsonResponse
    {
        $existing = InboundSnapshot::query()->find(strtolower($snapshot['id']));

        if ($existing === null) {
            return null;
        }

        if ($existing->endpoint !== $endpoint || $existing->total_parts !== $snapshot['total_parts']) {
            return IntegrationApi::error($request, 409, 'snapshot_mismatch', 'total_parts or endpoint differs from earlier parts of this snapshot.');
        }

        if ($existing->status !== InboundSnapshot::COLLECTING) {
            return IntegrationApi::error($request, 409, 'snapshot_mismatch', 'This snapshot is already '.$existing->status.'; start a new snapshot id.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function storeRejected(Request $request, IntegrationToken $token, string $endpoint, InboundPayload $payload, ?string $batchId, string $hash, string $raw, array $response, int $status): JsonResponse
    {
        if ($batchId !== null) {
            try {
                $batch = InboundBatch::query()->create([
                    ...$this->baseAttributes($request, $token, $endpoint, $payload, $batchId, $hash, $raw),
                    'mode' => is_string($payload->data['mode'] ?? null) ? SyncMode::tryFrom($payload->data['mode']) : null,
                    'status' => InboundBatchStatus::Rejected,
                    'record_count' => $payload->recordCount(),
                    'rejected_count' => count($response['error']['rejected'] ?? []),
                    'rejected' => $response['error']['rejected'] ?? null,
                    'errors' => [['code' => $response['error']['code'], 'fields' => $response['error']['fields'] ?? []]],
                    'response_status' => $status,
                    'response_body' => $response,
                ]);
                InboundBatchPayload::query()->create(['inbound_batch_id' => $batch->id, 'payload' => $raw, 'created_at' => now()]);
            } catch (QueryException) {
                // Lost a race with an identical batch; the response is the same anyway.
            }
        }

        $request->attributes->set(IntegrationApi::ERROR_CODE_ATTRIBUTE, $response['error']['code']);

        return response()->json($response, $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseAttributes(Request $request, IntegrationToken $token, string $endpoint, InboundPayload $payload, ?string $batchId, string $hash, string $raw): array
    {
        return [
            'batch_id' => $batchId,
            'integration_token_id' => $token->id,
            'endpoint' => $endpoint,
            'payload_hash' => $hash,
            'source' => is_array($payload->data['source'] ?? null) ? $payload->data['source'] : null,
            'ip' => $request->ip(),
            'request_id' => IntegrationApi::requestId($request),
            'received_at' => now(),
        ];
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @return array{error: array<string, mixed>}
     */
    protected function errorBody(Request $request, string $code, string $message, array $fields): array
    {
        return ['error' => ['code' => $code, 'message' => $message, 'fields' => $fields, 'request_id' => IntegrationApi::requestId($request)]];
    }

    protected function stripBom(string $raw): string
    {
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }
}
