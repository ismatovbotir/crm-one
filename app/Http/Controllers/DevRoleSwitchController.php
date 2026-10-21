<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Development-only role switcher (user request 2026-10-19): signs in as another active staff user so
 * the interface and menu can be checked per role. The route exists only when APP_ENV=local and the
 * controller refuses everywhere else; the first user is remembered so the top bar can switch back.
 */
class DevRoleSwitchController extends Controller
{
    public const SESSION_KEY = 'dev_original_user_id';

    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $validated = $request->validate(['user_id' => ['required', 'integer']]);
        $target = User::query()->internal()->active()->findOrFail((int) $validated['user_id']);
        $original = (int) ($request->session()->get(self::SESSION_KEY) ?? $request->user()?->id);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();

        if ($target->id === $original) {
            $request->session()->forget(self::SESSION_KEY);
        } else {
            $request->session()->put(self::SESSION_KEY, $original);
        }

        return redirect()->route('dashboard');
    }
}
