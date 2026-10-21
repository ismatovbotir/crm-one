<?php

namespace App\Services\OneC;

use App\Enums\OneCFeed;
use App\Models\IntegrationFeed;
use App\Models\Setting;
use App\Models\User;

/**
 * Effective 1C integration settings (Spec 02 section 7.1): admin-edited `settings` rows override
 * the `.env` defaults in config/onec.php.
 */
class OneCSettings
{
    public const INBOUND_ENABLED_KEY = 'onec.inbound_enabled';

    public const STALE_MINUTES_KEY = 'onec.stale_minutes.';

    public const CUSTOMERS_ENABLED_KEY = 'onec.customers_enabled';

    public const CUSTOMERS_POLL_STALE_KEY = 'onec.customers_poll_stale_minutes';

    /** Spec 02 rev. 4: may 1C create a new partner when no tax ID matches (default on). */
    public const ALLOW_CREATE_KEY = 'integrations.allow_create_from_1c';

    /**
     * Customer exchange switch (Spec 02 rev. 3): the setting overrides config onec.enabled.
     */
    public function customersEnabled(): bool
    {
        $stored = Setting::get(self::CUSTOMERS_ENABLED_KEY);

        return $stored === null ? (bool) config('onec.enabled') : $stored === '1';
    }

    public function allowCreateFromOneC(): bool
    {
        $stored = Setting::get(self::ALLOW_CREATE_KEY);

        return $stored === null ? true : $stored === '1';
    }

    public function customerPollStaleMinutes(): int
    {
        $stored = Setting::get(self::CUSTOMERS_POLL_STALE_KEY);

        return $stored !== null && ctype_digit($stored) && (int) $stored > 0 ? (int) $stored : max(1, (int) config('onec.customers.poll_stale_minutes', 30));
    }

    /**
     * BR-I.23: the exchange is on and 1C has not polled the outbox within the threshold.
     */
    public function customerPollIsStale(): bool
    {
        if (! $this->customersEnabled()) {
            return false;
        }

        $last = IntegrationFeed::lastContact(IntegrationFeed::CUSTOMERS_POLL);

        return $last === null || $last->lt(now()->subMinutes($this->customerPollStaleMinutes()));
    }

    public function saveCustomerExchange(bool $enabled, int $pollStaleMinutes, ?User $by = null, ?bool $allowCreate = null): void
    {
        Setting::putMany([
            ...($allowCreate !== null ? [self::ALLOW_CREATE_KEY => $allowCreate ? '1' : '0'] : []),
            self::CUSTOMERS_ENABLED_KEY => $enabled ? '1' : '0',
            self::CUSTOMERS_POLL_STALE_KEY => (string) $pollStaleMinutes,
        ], $by);
    }

    public function inboundEnabled(): bool
    {
        $stored = Setting::get(self::INBOUND_ENABLED_KEY);

        return $stored === null ? (bool) config('onec.inbound.enabled') : $stored === '1';
    }

    public function staleMinutes(OneCFeed $feed): int
    {
        $stored = Setting::get(self::STALE_MINUTES_KEY.$feed->value);

        return $stored !== null && ctype_digit($stored) && (int) $stored > 0 ? (int) $stored : $feed->defaultStaleMinutes();
    }

    /**
     * @param  array<string, int>  $staleMinutes  feed value => minutes
     */
    public function save(bool $inboundEnabled, array $staleMinutes, ?User $by = null): void
    {
        $values = [self::INBOUND_ENABLED_KEY => $inboundEnabled ? '1' : '0'];

        foreach (OneCFeed::cases() as $feed) {
            if (isset($staleMinutes[$feed->value])) {
                $values[self::STALE_MINUTES_KEY.$feed->value] = (string) $staleMinutes[$feed->value];
            }
        }

        Setting::putMany($values, $by);
    }

    /**
     * Base URL to give the 1C team.
     */
    public function apiBaseUrl(): string
    {
        return url('/api/v1/integration/1c');
    }
}
