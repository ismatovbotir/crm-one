<?php

namespace App\Http\Middleware\Integration;

use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST bodies must be JSON (415 `unsupported_media_type`) and within the size limit
 * (413 `payload_too_large`, checked before parsing).
 */
class EnsureJsonPayload
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('onec.inbound.max_body_kb') * 1024;
        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > $maxBytes || strlen($request->getContent()) > $maxBytes) {
            return IntegrationApi::error($request, 413, 'payload_too_large', 'The request body exceeds the limit; split the batch.');
        }

        $type = strtolower((string) $request->headers->get('Content-Type', ''));

        if (! str_starts_with($type, 'application/json')) {
            return IntegrationApi::error($request, 415, 'unsupported_media_type', 'Send Content-Type: application/json; charset=utf-8.');
        }

        return $next($request);
    }
}
