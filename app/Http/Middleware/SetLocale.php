<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface language: an explicit choice in this session (the switcher), then the user's default
 * language (users.locale, set in Users or My cabinet; applied at sign-in), then the app default (uz).
 * Unsupported values anywhere in the chain are ignored.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locale::fromAvailable($request->session()->get(Locale::SESSION_KEY))
            ?? Locale::fromAvailable($request->user()?->locale?->value)
            ?? Locale::default();

        App::setLocale($locale->value);

        return $next($request);
    }
}
