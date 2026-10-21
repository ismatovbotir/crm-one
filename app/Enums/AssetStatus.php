<?php

namespace App\Enums;

/**
 * Customer-facing status of a unit in the customer's equipment register (Spec 09 §5.1). The
 * sold-serial status of the registry is separate and unchanged. Badges carry text and an icon.
 */
enum AssetStatus: string
{
    case InUse = 'in_use';
    case NeedsRepair = 'needs_repair';
    case InRepair = 'in_repair';
    case RepairedReady = 'repaired_ready';
    case OutOfService = 'out_of_service';

    /**
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::InUse => [self::NeedsRepair, self::OutOfService],
            self::NeedsRepair => [self::InRepair, self::InUse, self::OutOfService],
            self::InRepair => [self::RepairedReady, self::InUse],
            self::RepairedReady => [self::InUse, self::NeedsRepair, self::OutOfService],
            self::OutOfService => [self::InUse],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    public function label(): string
    {
        return __('service.asset_statuses.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string} badge tone and icon
     */
    public function badge(): array
    {
        return match ($this) {
            self::InUse => ['dark', 'check'],
            self::NeedsRepair => ['red', 'warning'],
            self::InRepair => ['outline', 'settings'],
            self::RepairedReady => ['silver', 'check'],
            self::OutOfService => ['danger-outline', 'archive'],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
