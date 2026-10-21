<?php

namespace App\Http\Middleware\Integration;

use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-token IP/CIDR allowlist (AC-I.3, 403 `ip_not_allowed`). The client IP is $request->ip(),
 * which honours X-Forwarded-For only from proxies configured as trusted (BR-I.18a); none are
 * trusted by default, so a spoofed header from the internet changes nothing.
 */
class EnsureIntegrationIpAllowed
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = IntegrationApi::token($request);

        if ($token !== null && ! $token->allowsIp($request->ip())) {
            return IntegrationApi::error($request, 403, 'ip_not_allowed', 'This token may not be used from this address.');
        }

        return $next($request);
    }
}
