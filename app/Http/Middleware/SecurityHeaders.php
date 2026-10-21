<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers for every web response (security audit, CWE-1021 / CWE-693).
 *
 * Content-Security-Policy choices:
 * - A fresh nonce per request (Vite::useCspNonce()). Vite tags, the @fonts <style>, Livewire's
 *   own <style>/<script> tags (they read Vite::cspNonce()) and our inline <style> blocks carry
 *   it; Livewire re-writes nonces on wire:navigate. No 'unsafe-inline' for scripts, so an
 *   injected <script> or on*= handler does not run.
 * - No 'unsafe-eval': Livewire runs the CSP-safe Alpine build (config livewire.csp_safe), which
 *   parses x-/wire: expressions instead of calling new Function(). Expressions in Blade stay
 *   simple; anything larger is an Alpine.data() component in resources/js/app.js.
 * - style-src-attr 'unsafe-inline' only for style="" attributes (colour swatches, theme preview,
 *   category indent, Livewire morphing); <style> elements still need the nonce.
 * - When the Vite dev server is running (public/hot) its origin is allowed for scripts,
 *   styles and the HMR websocket.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $defaults = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if (app()->environment('production')) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        // A route may set a stricter value itself (e.g. Referrer-Policy: no-referrer); keep it.
        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    protected function contentSecurityPolicy(string $nonce): string
    {
        $dev = $this->viteDevServer();
        $devHttp = $dev !== null ? ' '.$dev : '';
        $devWs = $dev !== null ? ' '.preg_replace('#^http#', 'ws', $dev) : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$devHttp}",
            "style-src 'self' 'nonce-{$nonce}'{$devHttp}",
            "style-src-attr 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:{$devHttp}",
            "connect-src 'self'{$devHttp}{$devWs}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Origin of the running Vite dev server (from public/hot), or null.
     */
    protected function viteDevServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $url = trim((string) @file_get_contents(public_path('hot')));
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
