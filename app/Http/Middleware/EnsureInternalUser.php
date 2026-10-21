<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal screens are for staff only (Spec 03 DEC-1). A portal user who somehow holds an
 * internal session, or a session that also holds a portal login, is signed out of both realms.
 */
class EnsureInternalUser
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (! $user->isInternal() || Auth::guard('portal')->check())) {
            Auth::guard('web')->logout();
            Auth::guard('portal')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                abort(404);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }
}
