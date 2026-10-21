<?php

namespace App\Http\Middleware\Integration;

use App\Services\OneC\OneCSettings;
use App\Support\IntegrationApi;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BR-I.22 / AC-I.8: while the inbound API is switched off, writes get 503 `integration_disabled`
 * (1C retries later) and nothing is stored.
 */
class EnsureIntegrationEnabled
{
    public function __construct(protected OneCSettings $settings) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $channel = 'catalog'): Response
    {
        $enabled = $channel === 'customers' ? $this->settings->customersEnabled() : $this->settings->inboundEnabled();

        if (! $enabled) {
            return IntegrationApi::error($request, 503, 'integration_disabled', 'The 1C integration is disabled in the CRM.', headers: ['Retry-After' => '300']);
        }

        return $next($request);
    }
}
