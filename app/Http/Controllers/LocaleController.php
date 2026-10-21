<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches the interface language for this session. With "remember" (signed in) it also becomes the
 * user's default language (users.locale), used at sign-in, in notifications, e-mails and Telegram.
 */
class LocaleController extends Controller
{
    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->locale();

        $request->session()->put(Locale::SESSION_KEY, $locale->value);
        if ($request->boolean('remember')) {
            $request->user()?->forceFill(['locale' => $locale])->save();
        }

        return redirect()->to($this->sameHostPreviousUrl($request));
    }

    /**
     * The Referer header is client-controlled (CWE-601): only go back to a URL on our own
     * scheme+host; anything else lands on the dashboard (guests are sent on to the login page).
     */
    protected function sameHostPreviousUrl(Request $request): string
    {
        $previous = (string) $request->headers->get('referer', '');
        $fallback = route('dashboard');

        if ($previous === '') {
            return $fallback;
        }

        $target = parse_url($previous);

        if (! is_array($target) || ! isset($target['scheme'], $target['host']) || str_contains($previous, '\\')) {
            return $fallback;
        }

        $origin = strtolower($target['scheme'].'://'.$target['host'].(isset($target['port']) ? ':'.$target['port'] : ''));

        if ($origin !== strtolower($request->getSchemeAndHttpHost())) {
            return $fallback;
        }

        // Rebuild on our own origin so no parser differential can smuggle another host.
        $path = '/'.ltrim($target['path'] ?? '/', '/');

        return $request->getSchemeAndHttpHost().$path.(isset($target['query']) ? '?'.$target['query'] : '');
    }
}
