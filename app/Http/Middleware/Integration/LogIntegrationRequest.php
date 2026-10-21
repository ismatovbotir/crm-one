<?php

namespace App\Http\Middleware\Integration;

use App\Models\InboundBatch;
use App\Models\IntegrationLog;
use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Outermost middleware of the 1C API: assigns/echoes X-Request-Id and writes one
 * `integration_logs` row per call (Spec 02 BR-I.19). Only method, path without query, status,
 * duration, request id, token prefix and error code are recorded: never bodies or tokens.
 */
class LogIntegrationRequest
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $requestId = IntegrationApi::requestId($request);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        try {
            $this->write($request, $response, (int) round((hrtime(true) - $started) / 1_000_000));
        } catch (Throwable $exception) {
            report($exception); // Logging must never break the API response.
        }

        return $response;
    }

    protected function write(Request $request, Response $response, int $durationMs): void
    {
        $status = $response->getStatusCode();
        $batchId = $request->attributes->get(IntegrationApi::BATCH_ATTRIBUTE);

        IntegrationLog::query()->create([
            'inbound_batch_id' => is_string($batchId) ? InboundBatch::query()->where('batch_id', $batchId)->value('id') : null,
            'direction' => 'inbound',
            'level' => $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info'),
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
            'http_status' => $status,
            'duration_ms' => $durationMs,
            'request_id' => IntegrationApi::requestId($request),
            'token_prefix' => IntegrationApi::token($request)?->token_prefix,
            'error_code' => $request->attributes->get(IntegrationApi::ERROR_CODE_ATTRIBUTE),
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
