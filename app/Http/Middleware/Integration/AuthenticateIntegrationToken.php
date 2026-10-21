<?php

namespace App\Http\Middleware\Integration;

use App\Models\IntegrationToken;
use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token authentication for the 1C API (Spec 02 7.1, AC-I.1). Missing, unknown, revoked or
 * expired tokens all get the same generic 401; session cookies are never consulted (stateless).
 */
class AuthenticateIntegrationToken
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain === '' ? null : IntegrationToken::findActive($plain);

        if ($token === null) {
            return IntegrationApi::error($request, 401, 'unauthenticated', 'Invalid or missing API token.', headers: ['WWW-Authenticate' => 'Bearer']);
        }

        $request->attributes->set(IntegrationApi::TOKEN_ATTRIBUTE, $token);
        $token->recordUse($request->ip());

        return $next($request);
    }
}
