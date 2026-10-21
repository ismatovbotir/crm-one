<?php

namespace App\Http\Controllers\Api\V1\Integration\OneC;

use App\Enums\InboundBatchStatus;
use App\Enums\SyncMode;
use App\Http\Controllers\Controller;
use App\Models\CustomerOutboxEntry;
use App\Models\InboundBatch;
use App\Models\IntegrationFeed;
use App\Services\OneC\Customers\CustomerInboundUpserter;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\Customers\CustomerPayload;
use App\Services\OneC\Inbound\BatchValidator;
use App\Support\IntegrationApi;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer exchange with 1C (Spec 02 rev. 3, section 10). 1C is on a private network and
 * initiates every call: it polls the outbox of CRM customers, acknowledges them, and pushes its
 * own counterparties. Authentication, abilities, switch and rate limit are middleware.
 */
class CustomerExchangeController extends Controller
{
    public function __construct(protected CustomerOutbox $outbox, protected CustomerPayload $payload) {}

    /**
     * GET /customers/pending?limit=&cursor= (customers:read).
     */
    public function pending(Request $request): JsonResponse
    {
        $limit = $request->query('limit', '100');
        $cursor = $request->query('cursor', '0');
        $max = max(1, (int) config('onec.customers.max_per_poll'));
        $fields = [];

        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > $max) {
            $fields['limit'] = ['between_1_and_'.$max];
        }

        if (! is_string($cursor) || ($cursor !== '' && ! ctype_digit($cursor))) {
            $fields['cursor'] = ['invalid_cursor'];
        }

        if ($fields !== []) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'Invalid paging parameters.', $fields);
        }

        IntegrationFeed::touchContact(IntegrationFeed::CUSTOMERS_POLL);
        $page = $this->outbox->poll((int) $cursor, (int) $limit);
        $entries = $page['entries'];

        return response()->json([
            'data' => $entries->map(fn (CustomerOutboxEntry $entry): array => $this->payload->forEntry($entry))->all(),
            'next_cursor' => $entries->isEmpty() ? (string) (int) $cursor : (string) $entries->last()->id,
            'has_more' => $page['has_more'],
            'server_time' => $this->payload->time(now()),
            'request_id' => IntegrationApi::requestId($request),
        ]);
    }

    /**
     * POST /customers/ack (customers:write): one ack object or {"acks": [...]}. Idempotent.
     */
    public function ack(Request $request): JsonResponse
    {
        $data = $this->decode($request);

        if ($data === null) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The body must be a JSON object.', ['body' => ['invalid_json']]);
        }

        $acks = array_key_exists('acks', $data) ? $data['acks'] : [$data];
        $max = max(1, (int) config('onec.customers.max_acks'));

        if (! is_array($acks) || ! array_is_list($acks) || $acks === [] || count($acks) > $max) {
            return IntegrationApi::error($request, 422, 'validation_failed', "Send 1..{$max} acknowledgements.", ['acks' => ['invalid_count']]);
        }

        IntegrationFeed::touchContact(IntegrationFeed::CUSTOMERS_ACK);
        $results = [];

        foreach ($acks as $index => $ack) {
            $result = is_array($ack) && ! array_is_list($ack)
                ? $this->outbox->acknowledge($ack)
                : ['crm_id' => null, 'outbox_id' => null, 'result' => 'rejected', 'errors' => [['field' => null, 'code' => 'validation_failed']]];

            $results[] = ['index' => $index, ...$result];
        }

        $rejected = collect($results)->contains('result', 'rejected');

        return response()->json(['results' => $results, 'request_id' => IntegrationApi::requestId($request)], $rejected ? 207 : 200);
    }

    /**
     * POST /customers (customers:write): synchronous upsert of 1C counterparties, idempotent by
     * batch_id. Only the hash and the response are stored (the body holds personal data).
     */
    public function upsert(Request $request, CustomerInboundUpserter $upserter, BatchValidator $validator): JsonResponse
    {
        $raw = $this->raw($request);
        $hash = hash('sha256', $raw);
        $data = $this->decode($request);

        if ($data === null) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The body must be a JSON object.', ['body' => ['invalid_json']]);
        }

        $batchId = is_string($data['batch_id'] ?? null) && preg_match(BatchValidator::GUID_PATTERN, $data['batch_id']) === 1 ? strtolower($data['batch_id']) : null;
        $customers = $data['customers'] ?? null;
        $max = max(1, (int) config('onec.customers.max_per_push'));
        $fields = [];

        if ($batchId === null) {
            $fields['batch_id'] = ['required_uuid'];
        }

        if (! $validator->isDateTime($data['sent_at'] ?? null)) {
            $fields['sent_at'] = ['invalid_datetime'];
        }

        if (! is_array($customers) || ! array_is_list($customers) || $customers === [] || count($customers) > $max) {
            $fields['customers'] = ['between_1_and_'.$max];
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

        IntegrationFeed::touchContact(IntegrationFeed::CUSTOMERS_PUSH);
        $results = [];

        foreach ($customers as $index => $record) {
            $results[] = $upserter->upsert($record, $index);
        }

        $counts = collect($results)->countBy('result')->all();
        $problems = ($counts['failed'] ?? 0) + ($counts['conflict'] ?? 0);
        $status = $problems > 0 ? 207 : 200;
        $body = ['batch_id' => $batchId, 'results' => $results, 'request_id' => IntegrationApi::requestId($request)];

        try {
            InboundBatch::query()->create([
                'batch_id' => $batchId,
                'integration_token_id' => IntegrationApi::token($request)?->id,
                'endpoint' => 'customers',
                'mode' => SyncMode::Delta,
                'status' => $problems > 0 ? InboundBatchStatus::Partial : InboundBatchStatus::Processed,
                'payload_hash' => $hash,
                'record_count' => count($customers),
                'accepted_count' => count($customers) - $problems,
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

    protected function replay(Request $request, string $batchId, string $hash): ?JsonResponse
    {
        $existing = InboundBatch::query()->where('batch_id', $batchId)->first();

        if ($existing === null) {
            return null;
        }

        if (! hash_equals($existing->payload_hash, $hash) || $existing->integration_token_id !== IntegrationApi::token($request)?->id) {
            return IntegrationApi::error($request, 409, 'idempotency_key_reused', 'This batch_id was already used with a different body.');
        }

        return response()->json($existing->response_body, $existing->response_status, ['Idempotent-Replayed' => 'true']);
    }

    protected function raw(Request $request): string
    {
        $raw = $request->getContent();

        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decode(Request $request): ?array
    {
        $data = json_decode($this->raw($request), true);

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
