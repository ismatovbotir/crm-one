<?php

namespace App\Services\OneC;

use App\Enums\IntegrationAbility;
use App\Models\IntegrationToken;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The single "1C API token" shown in Settings > Integrations: a plain bearer token (all abilities,
 * no HMAC, no IP list) that the admin can copy at any time and refresh. It is an ordinary
 * IntegrationToken, so the API checks (hash lookup, expiry, revocation, rate limit) are unchanged;
 * the only difference from the advanced tokens is that its plain value is also kept encrypted in
 * the settings so it can be shown again. Refreshing revokes the previous one at once.
 */
class SimpleToken
{
    public const NAME = '1C API token';

    private const VALUE_KEY = 'onec.simple_token';

    private const ID_KEY = 'onec.simple_token_id';

    /**
     * The active token row, or null when none was generated yet or it expired / was revoked.
     */
    public function model(): ?IntegrationToken
    {
        $id = Setting::get(self::ID_KEY);

        return $id === null ? null : IntegrationToken::query()->active()->find((int) $id);
    }

    /**
     * The plain token for the copy button; null when there is no active simple token.
     */
    public function plain(): ?string
    {
        $stored = Setting::get(self::VALUE_KEY);

        if ($stored === null || $this->model() === null) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Issue a new token, revoke the previous simple one and return the new plain value.
     */
    public function refresh(User $by): string
    {
        $previous = $this->model();
        $issued = IntegrationToken::issue(self::NAME, IntegrationAbility::values(), null, (int) config('onec.inbound.token_max_days'), false, $by);
        $previous?->revoke($by);

        Setting::putMany([
            self::VALUE_KEY => Crypt::encryptString($issued['plain']),
            self::ID_KEY => (string) $issued['token']->id,
        ], $by);

        return $issued['plain'];
    }
}
