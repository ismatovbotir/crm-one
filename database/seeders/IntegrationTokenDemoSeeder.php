<?php

namespace Database\Seeders;

use App\Enums\IntegrationAbility;
use App\Models\IntegrationToken;
use App\Services\OneC\OneCSettings;
use Illuminate\Database\Seeder;

/**
 * Dev-only 1C API token with a well-known value, limited to loopback, so the inbound API can be
 * tried with curl. Also switches the inbound API on. Never runs in production; real tokens are
 * created on Settings > Integrations and shown once.
 */
class IntegrationTokenDemoSeeder extends Seeder
{
    /** 1c_ + 48 chars, like real tokens. Dev databases only. */
    public const DEV_TOKEN = '1c_devonly_local_token_change_me_000000000000000000';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Skipping the dev 1C token in production.');

            return;
        }

        if (! IntegrationToken::query()->where('token_hash', IntegrationToken::hashToken(self::DEV_TOKEN))->exists()) {
            IntegrationToken::issue('1C dev (local only)', IntegrationAbility::values(), ['127.0.0.1', '::1'], 365, plain: self::DEV_TOKEN);
        }

        app(OneCSettings::class)->save(true, []);
    }
}
