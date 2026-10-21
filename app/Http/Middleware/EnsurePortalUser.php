<?php

namespace App\Http\Middleware;

use App\Support\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal realm guard (Spec 03 4.1, BR-A.3): only active portal users with an active membership of
 * a portal-enabled customer; sets the default guard to `portal` and loads PortalContext. A
 * session that also holds a staff login is signed out of both (no simultaneous realms).
 */
class EnsurePortalUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('portal')->user();
        $membership = $user !== null && $user->isPortal() && $user->is_active ? $user->activeMembership() : null;

        if ($user === null) {
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(404);
            }

            return redirect()->route('portal.login');
        }

        if ($membership === null || Auth::guard('web')->check()) {
            Auth::guard('portal')->logout();
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(404);
            }

            return redirect()->route('portal.login')->with('status', __('portal.login.access_ended'));
        }

        Auth::shouldUse('portal');
        app(PortalContext::class)->set($user, $membership);

        return $next($request);
    }
}
