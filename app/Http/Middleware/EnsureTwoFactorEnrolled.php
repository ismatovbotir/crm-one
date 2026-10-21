<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Roadmap step 6: when 2FA is enforced for the user's role and not set up yet, every staff page
 * except the cabinet (where it is set up) and logout leads to the cabinet.
 */
class EnsureTwoFactorEnrolled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $request->routeIs('cabinet', 'logout') || ! app(TwoFactor::class)->required($user) || app(TwoFactor::class)->enabled($user)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
            abort(403, __('auth.two_factor.required'));
        }

        session()->flash('error', __('auth.two_factor.required'));

        return redirect()->route('cabinet');
    }
}
