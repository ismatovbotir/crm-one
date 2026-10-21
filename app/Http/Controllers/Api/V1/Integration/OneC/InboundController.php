<?php

namespace App\Http\Controllers\Api\V1\Integration\OneC;

use App\Actions\OneC\ReceiveInboundBatch;
use App\Http\Controllers\Controller;
use App\Models\InboundBatch;
use App\Services\OneC\Inbound\InboundPayload;
use App\Services\OneC\OneCSettings;
use App\Support\IntegrationApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * 1C -> CRM inbound catalog API v1 (Spec 02 section 9). Thin: authentication, abilities and limits
 * are middleware; intake is ReceiveInboundBatch; processing is queued.
 */
class InboundController extends Controller
{
    public function __construct(protected ReceiveInboundBatch $receive) {}

    public function ping(Request $request, OneCSettings $settings): JsonResponse
    {
        $token = IntegrationApi::token($request);

        return response()->json([
            'status' => 'ok',
            'api_version' => '1.0',
            'server_time' => now()->toIso8601String(),
            'token' => ['name' => $token?->name, 'abilities' => $token?->abilities ?? [], 'expires_at' => $token?->expires_at->toIso8601String()],
            'integration_enabled' => $settings->inboundEnabled(),
            'request_id' => IntegrationApi::requestId($request),
        ]);
    }

    public function items(Request $request): JsonResponse
    {
        return $this->receive->handle($request, IntegrationApi::token($request), 'items');
    }

    public function itemStock(Request $request): JsonResponse
    {
        return $this->receive->handle($request, IntegrationApi::token($request), 'stock');
    }

    /**
     * Combined batch: the token needs the ability of every section present (Spec 02 section 3).
     */
    public function batch(Request $request): JsonResponse
    {
        $token = IntegrationApi::token($request);
        $data = json_decode(ltrim($request->getContent(), "\xEF\xBB\xBF"), true);

        if (is_array($data)) {
            foreach ((new InboundPayload('batch', $data))->feeds() as $feed) {
                if (! $token->hasAbility($feed->ability())) {
                    return IntegrationApi::error($request, 403, 'forbidden_ability', "This token lacks the [{$feed->ability()->value}] ability.");
                }
            }
        }

        return $this->receive->handle($request, $token, 'batch');
    }

    /**
     * Processing status of a batch sent by the same token; any other batch id is 404 (no IDOR).
     */
    public function showBatch(Request $request, string $batchId): JsonResponse
    {
        $token = IntegrationApi::token($request);
        $batch = Str::isUuid($batchId)
            ? InboundBatch::query()->where('batch_id', strtolower($batchId))->where('integration_token_id', $token->id)->first()
            : null;

        if ($batch === null) {
            return IntegrationApi::error($request, 404, 'not_found', 'Batch not found.');
        }

        return response()->json([
            'batch_id' => $batch->batch_id,
            'status' => $batch->status->value,
            'mode' => $batch->mode?->value,
            'endpoint' => $batch->endpoint,
            'received_at' => $batch->received_at->toIso8601String(),
            'processed_at' => $batch->processed_at?->toIso8601String(),
            'counts' => $batch->counts ?? ['rejected' => (int) $batch->rejected_count],
            'rejected' => $batch->rejected ?? [],
            'errors' => $batch->errors ?? [],
            'snapshot' => $batch->snapshot_id === null ? null : ['id' => $batch->snapshot_id, 'part' => $batch->snapshot_part, 'total_parts' => $batch->snapshot_total_parts],
            'request_id' => IntegrationApi::requestId($request),
        ]);
    }
}
