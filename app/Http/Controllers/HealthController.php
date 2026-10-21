<?php

namespace App\Http\Controllers;

use App\Services\Maintenance\HealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Health endpoint for monitoring (roadmap step 10): `GET /api/health`. Everyone gets the overall
 * status (200 ok / 503 fail); the per-check details need the `X-Health-Token` header
 * (operations.health.token) or a request from the server itself. Never cached.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request, HealthCheck $health): JsonResponse
    {
        $report = $health->run();
        $token = (string) config('operations.health.token');
        $detailed = ($token !== '' && hash_equals($token, (string) $request->header('X-Health-Token')))
            || ($token === '' && in_array($request->ip(), ['127.0.0.1', '::1'], true));

        return response()->json($detailed ? $report : ['status' => $report['status']], $report['status'] === 'ok' ? 200 : 503, [
            'Cache-Control' => 'no-store',
        ]);
    }
}
