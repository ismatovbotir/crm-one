<?php

namespace App\Http\Controllers\Api\V1\Integration\OneC;

use App\Enums\InboundBatchStatus;
use App\Enums\SyncMode;
use App\Models\InboundBatch;
use App\Models\IntegrationFeed;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\Inbound\GoodsReceiptImporter;
use App\Support\IntegrationApi;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Goods receipts created in 1C (Spec 06 section 26, Spec 02 rev. 5 section 10.6). 1C pushes
 * them (warehouse:write); the CRM stock manager completes them and the posted result is available
 * for 1C to pull from the warehouse feed of the outbox. Envelope, idempotency by batch_id and the
 * batch log follow POST /customers.
 */
class GoodsReceiptExchangeController extends CustomerExchangeController
{
    /** Per-record results that mean "the CRM did not take this change" (HTTP 409 on PUT). */
    public const CONFLICTS = ['stale_version', 'stale_update', 'receipt_posted', 'receipt_cancelled', 'line_has_serials', 'id_mismatch', 'not_from_1c', 'item_code_taken'];

    /**
     * POST /goods-receipts: {batch_id, sent_at, receipts: [...]}; 200, or 207 when any receipt failed.
     */
    public function push(Request $request, GoodsReceiptImporter $importer, BatchValidator $validator): JsonResponse
    {
        $raw = $this->raw($request);
        $hash = hash('sha256', $raw);
        $data = $this->decode($request);

        if ($data === null) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The body must be a JSON object.', ['body' => ['invalid_json']]);
        }

        $batchId = is_string($data['batch_id'] ?? null) && preg_match(BatchValidator::GUID_PATTERN, $data['batch_id']) === 1 ? strtolower($data['batch_id']) : null;
        $receipts = $data['receipts'] ?? null;
        $max = max(1, (int) config('onec.goods_receipts.max_per_push', 50));
        $fields = [];

        if ($batchId === null) {
            $fields['batch_id'] = ['required_uuid'];
        }

        if (! $validator->isDateTime($data['sent_at'] ?? null)) {
            $fields['sent_at'] = ['invalid_datetime'];
        }

        if (! is_array($receipts) || ! array_is_list($receipts) || $receipts === [] || count($receipts) > $max) {
            $fields['receipts'] = ['between_1_and_'.$max];
        }

        if ($batchId !== null) {
            $request->attributes->set(IntegrationApi::BATCH_ATTRIBUTE, $batchId);

            if ($replay = $this->replay($request, $batchId, $hash)) {
                return $replay;
            }
        }

        if ($fields !== []) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The batch envelope is invalid.', $fields);
        }

        IntegrationFeed::touchContact(IntegrationFeed::GOODS_RECEIPTS_PUSH);
        $results = [];

        foreach ($receipts as $index => $record) {
            $results[] = $importer->upsert($record, $index);
        }

        $counts = collect($results)->countBy('result')->all();
        $problems = $counts['failed'] ?? 0;
        $status = $problems > 0 ? 207 : 200;
        $body = ['batch_id' => $batchId, 'results' => $results, 'request_id' => IntegrationApi::requestId($request)];

        try {
            InboundBatch::query()->create([
                'batch_id' => $batchId,
                'integration_token_id' => IntegrationApi::token($request)?->id,
                'endpoint' => 'goods_receipts',
                'mode' => SyncMode::Delta,
                'status' => $problems > 0 ? InboundBatchStatus::Partial : InboundBatchStatus::Processed,
                'payload_hash' => $hash,
                'record_count' => count($receipts),
                'accepted_count' => count($receipts) - $problems,
                'rejected_count' => $problems,
                'counts' => $counts,
                'response_status' => $status,
                'response_body' => $body,
                'ip' => $request->ip(),
                'request_id' => IntegrationApi::requestId($request),
                'received_at' => now(),
                'processed_at' => now(),
            ]);
        } catch (QueryException $exception) {
            if ($replay = $this->replay($request, $batchId, $hash)) {
                return $replay;
            }

            throw $exception;
        }

        return response()->json($body, $status);
    }

    /**
     * PUT /goods-receipts/{crmId}: one receipt (same fields) updated by its CRM id. Idempotent by
     * content; 200 applied/unchanged, 409 stale or refused change, 422 invalid.
     */
    public function update(Request $request, string $crmId, GoodsReceiptImporter $importer): JsonResponse
    {
        if (preg_match(BatchValidator::GUID_PATTERN, $crmId) !== 1) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The CRM id must be a UUID.', ['crm_id' => ['invalid_uuid']]);
        }

        $data = $this->decode($request);

        if ($data === null) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The body must be a JSON object.', ['body' => ['invalid_json']]);
        }

        IntegrationFeed::touchContact(IntegrationFeed::GOODS_RECEIPTS_PUSH);
        $result = $importer->upsert($data, 0, strtolower($crmId));
        unset($result['index']);
        $codes = array_column($result['errors'] ?? [], 'code');
        $status = match (true) {
            $result['result'] !== 'failed' => 200,
            array_intersect($codes, self::CONFLICTS) !== [] => 409,
            default => 422,
        };

        return response()->json(['data' => $result, 'request_id' => IntegrationApi::requestId($request)], $status);
    }
}
