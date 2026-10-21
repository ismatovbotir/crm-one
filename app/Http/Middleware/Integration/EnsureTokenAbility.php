<?php

namespace App\Http\Middleware\Integration;

use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The token must carry the route's ability (AC-I.2, 403 `forbidden_ability`).
 * Usage: EnsureTokenAbility::class.':items:write'.
 */
class EnsureTokenAbility
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $ability = implode(':', $abilities);
        $token = IntegrationApi::token($request);

        if ($token === null || ! $token->hasAbility($ability)) {
            return IntegrationApi::error($request, 403, 'forbidden_ability', "This token lacks the [{$ability}] ability.");
        }

        return $next($request);
    }
}
