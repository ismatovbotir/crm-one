<?php

namespace App\Http\Middleware\Integration;

use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional HMAC request signing (Spec 02 9.1, AC-I.4, BR-I.18a).
 *
 * X-Signature: sha256=<hex(HMAC_SHA256(secret, X-Timestamp + "." + raw_body))>, X-Timestamp in
 * Unix seconds within the tolerance. Tokens with a signing secret must sign every request.
 * Without a secret, calls are accepted only from private networks (RFC 1918, loopback,
 * onec.inbound.private_networks); anything else gets 401 `signature_required`.
 */
class VerifyIntegrationSignature
{
    /** RFC 1918, loopback, IPv6 unique-local and loopback. */
    public const PRIVATE_RANGES = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.0/8', '::1/128', 'fc00::/7'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = IntegrationApi::token($request);

        if ($token === null) {
            return $next($request);
        }

        if (! $token->requiresSignature()) {
            if (! $this->isPrivateNetwork($request->ip())) {
                return IntegrationApi::error($request, 401, 'signature_required', 'Requests from outside the private network must be signed.');
            }

            return $next($request);
        }

        $timestamp = (string) $request->headers->get('X-Timestamp', '');
        $signature = (string) $request->headers->get('X-Signature', '');
        $tolerance = (int) config('onec.inbound.signature_tolerance_seconds');

        $valid = ctype_digit($timestamp)
            && abs(now()->getTimestamp() - (int) $timestamp) <= $tolerance
            && str_starts_with($signature, 'sha256=')
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), (string) $token->signing_secret), substr($signature, 7));

        if (! $valid) {
            return IntegrationApi::error($request, 401, 'invalid_signature', 'The request signature is invalid or expired.');
        }

        return $next($request);
    }

    protected function isPrivateNetwork(?string $ip): bool
    {
        return $ip !== null && IpUtils::checkIp($ip, [...self::PRIVATE_RANGES, ...config('onec.inbound.private_networks', [])]);
    }
}
