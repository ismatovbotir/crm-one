<?php

namespace App\Http\Controllers\Api\V1\Integration\OneC;

use App\Enums\InboundBatchStatus;
use App\Enums\SyncMode;
use App\Http\Controllers\Controller;
use App\Models\InboundBatch;
use App\Models\IntegrationFeed;
use App\Models\OnecOutboxEvent;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\OneC\Payments\PaymentInboundProcessor;
use App\Support\IntegrationApi;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRM → 1C document outbox and 1C → CRM payments (Spec 06 section 9, DEC-14). 1C initiates every
 * call: it polls `GET /outbox/pending?feed=`, acks with `POST /outbox/ack`, and pushes bank/cash
 * receipts with `POST /payments`. Feed abilities are checked here (the feed is a parameter).
 */
class OutboxController extends Controller
{
    public function __construct(protected OneCOutbox $outbox) {}

    /**
     * GET /outbox/pending?feed=orders|payments&limit=&cursor=
     */
    public function pending(Request $request): JsonResponse
    {
        $feed = $request->query('feed');
        $limit = $request->query('limit', '200');
        $cursor = $request->query('cursor', '0');
        $max = max(1, (int) config('accounting.outbox.max_per_poll'));

        if (! is_string($feed) || ! array_key_exists($feed, OneCOutbox::FEEDS)) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'Unknown feed.', ['feed' => ['invalid_feed']]);
        }

        if (! IntegrationApi::token($request)?->hasAbility(OneCOutbox::FEEDS[$feed])) {
            return IntegrationApi::error($request, 403, 'forbidden_ability', 'This token lacks the ['.OneCOutbox::FEEDS[$feed]->value.'] ability.');
        }

        if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > $max || ! is_string($cursor) || ($cursor !== '' && ! ctype_digit($cursor))) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'Invalid paging parameters.', ['limit' => ['between_1_and_'.$max]]);
        }

        IntegrationFeed::touchContact(IntegrationFeed::OUTBOX_POLL);
        $page = $this->outbox->poll($feed, (int) $cursor, (int) $limit);
        $events = $page['events'];

        return response()->json([
            'data' => $events->map(fn (OnecOutboxEvent $event): array => [
                'sequence' => $event->id,
                'event_id' => $event->event_id,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->setTimezone((string) config('orders.timezone'))->toIso8601String(),
                'aggregate' => ['type' => $event->aggregate_type, 'crm_id' => $event->aggregate_uuid, 'version' => $event->aggregate_version],
                'payload' => $event->payload,
            ])->all(),
            'next_cursor' => $events->isEmpty() ? null : (string) $events->last()->id,
            'has_more' => $page['has_more'],
            'server_time' => now()->setTimezone((string) config('orders.timezone'))->toIso8601String(),
            'request_id' => IntegrationApi::requestId($request),
        ]);
    }

    /**
     * POST /outbox/ack {"acks": [...]} — each ack needs the read ability of its event's feed.
     */
    public function ack(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $acks = is_array($data) ? ($data['acks'] ?? null) : null;
        $max = max(1, (int) config('accounting.outbox.max_acks'));

        if (! is_array($acks) || ! array_is_list($acks) || $acks === [] || count($acks) > $max) {
            return IntegrationApi::error($request, 422, 'validation_failed', "Send 1..{$max} acknowledgements.", ['acks' => ['invalid_count']]);
        }

        $token = IntegrationApi::token($request);
        $feeds = array_keys(array_filter(OneCOutbox::FEEDS, fn ($ability): bool => (bool) $token?->hasAbility($ability)));

        if ($feeds === []) {
            return IntegrationApi::error($request, 403, 'forbidden_ability', 'This token cannot read any outbox feed.');
        }

        IntegrationFeed::touchContact(IntegrationFeed::OUTBOX_ACK);
        $results = [];

        foreach ($acks as $index => $ack) {
            $results[] = ['index' => $index, ...(is_array($ack) && ! array_is_list($ack)
                ? $this->outbox->acknowledge($ack, $feeds)
                : ['event_id' => null, 'result' => 'rejected', 'errors' => [['field' => null, 'code' => 'validation_failed']]])];
        }

        $rejected = collect($results)->contains('result', 'rejected');

        return response()->json(['results' => $results, 'request_id' => IntegrationApi::requestId($request)], $rejected ? 207 : 200);
    }

    /**
     * POST /payments (payments:write): synchronous, idempotent by batch_id (only the hash and the
     * response are kept, the body holds payer data).
     */
    public function payments(Request $request, PaymentInboundProcessor $processor, BatchValidator $validator): JsonResponse
    {
        $raw = $request->getContent();
        $hash = hash('sha256', $raw);
        $data = json_decode($raw, true);

        if (! is_array($data) || array_is_list($data)) {
            return IntegrationApi::error($request, 422, 'validation_failed', 'The body must be a JSON object.', ['body' => ['invalid_json']]);
        }

        $batchId = is_string($data['batch_id'] ?? null) && preg_match(BatchValidator::GUID_PATTERN, $data['batch_id']) === 1 ? strtolower($data['batch_id']) : null;
        $payments = $data['payments'] ?? null;
        $max = max(1, (int) config('accounting.payments.max_per_push'));
        $fields = [];

        if ($batchId === null) {
            $fields['batch_id'] = ['required_uuid'];
        }

        if (! $validator->isDateTime($data['sent_at'] ?? null)) {
            $fields['sent_at'] = ['invalid_datetime'];
        }

        if (! is_array($payments) || ! array_is_list($payments) || $payments === [] || count($payments) > $max) {
            $fields['payments'] = ['between_1_and_'.$max];
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

        IntegrationFeed::touchContact(IntegrationFeed::PAYMENTS_PUSH);
        $results = [];

        foreach ($payments as $index => $record) {
            $results[] = $processor->process($record, $index);
        }

        $failed = collect($results)->where('result', 'failed')->count();
        $status = $failed > 0 ? 207 : 200;
        $body = ['batch_id' => $batchId, 'results' => $results, 'request_id' => IntegrationApi::requestId($request)];

        try {
            InboundBatch::query()->create([
                'batch_id' => $batchId,
                'integration_token_id' => IntegrationApi::token($request)?->id,
                'endpoint' => 'payments',
                'mode' => SyncMode::Delta,
                'status' => $failed > 0 ? InboundBatchStatus::Partial : InboundBatchStatus::Processed,
                'payload_hash' => $hash,
                'record_count' => count($payments),
                'accepted_count' => count($payments) - $failed,
                'rejected_count' => $failed,
                'counts' => collect($results)->countBy('result')->all(),
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
}
