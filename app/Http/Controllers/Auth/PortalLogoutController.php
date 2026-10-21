<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Portal sign-out (Spec 03 4.1): the portal guard is logged out, the session invalidated.
 */
class PortalLogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = Auth::guard('portal')->user();
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user !== null) {
            AuthEvent::record('portal', 'logout', $user);
        }

        return redirect()->route('portal.login');
    }
}
