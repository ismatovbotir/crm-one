<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The local "any credentials sign in" shortcut (intentional for development).
 *
 * It is allowed only when APP_ENV=local AND APP_DEBUG=true; every other combination
 * refuses it. At boot an unsafe configuration (debug on outside local) is logged so a
 * mis-deployed server is noticed, and the bypass stays off there regardless.
 */
final class DevLoginBypass
{
    private static bool $configurationChecked = false;

    public static function isAllowed(): bool
    {
        return app()->environment('local') && config('app.debug') === true;
    }

    /**
     * Boot-time check (once per process): warns about APP_DEBUG outside local, critical in production.
     */
    public static function assertSafeConfiguration(): void
    {
        if (self::$configurationChecked) {
            return;
        }

        self::$configurationChecked = true;

        if (app()->environment('local') || config('app.debug') !== true) {
            return;
        }

        $context = ['env' => app()->environment()];

        if (app()->environment('production')) {
            Log::critical('APP_DEBUG=true in production: error pages leak internals. Dev login bypass is refused; set APP_DEBUG=false.', $context);

            return;
        }

        Log::warning('APP_DEBUG=true outside the local environment. Dev login bypass is refused (needs APP_ENV=local and APP_DEBUG=true).', $context);
    }
}
