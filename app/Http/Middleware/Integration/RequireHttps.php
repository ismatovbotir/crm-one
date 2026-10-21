<?php

namespace App\Http\Middleware\Integration;

use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-I.18 / AC-I.5: the 1C API is HTTPS-only in production (403 `https_required`).
 * Behind a TLS-terminating proxy, configure trusted proxies so isSecure() sees the scheme.
 */
class RequireHttps
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            return IntegrationApi::error($request, 403, 'https_required', 'HTTPS is required.');
        }

        return $next($request);
    }
}
