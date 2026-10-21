<?php

namespace App\Enums;

/**
 * Inbound 1C feeds whose freshness is tracked (Spec 02 BR-I.21).
 */
enum OneCFeed: string
{
    case Items = 'items';
    case Stock = 'stock';

    public function label(): string
    {
        return __('integration.feeds.'.$this->value);
    }

    /** Default staleness threshold in minutes (config onec.stale_minutes, overridable in Settings). */
    public function defaultStaleMinutes(): int
    {
        return (int) config('onec.stale_minutes.'.$this->value, match ($this) {
            self::Items => 1440,
            self::Stock => 30,
        });
    }

    public function ability(): IntegrationAbility
    {
        return match ($this) {
            self::Items => IntegrationAbility::ItemsWrite,
            self::Stock => IntegrationAbility::StockWrite,
        };
    }
}
