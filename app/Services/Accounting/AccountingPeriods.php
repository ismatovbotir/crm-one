<?php

namespace App\Services\Accounting;

use App\Enums\PeriodStatus;
use App\Models\AccountingPeriod;
use App\Services\Accounting\Exceptions\AccountingActionException;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Accounting periods (Spec 06 BR-AC.13): "YYYY-MM" of a business date (Asia/Tashkent); money
 * actions dated in a locked period are refused, corrections go to the open period.
 */
class AccountingPeriods
{
    public static function of(DateTimeInterface|string $date): string
    {
        return CarbonImmutable::parse($date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date)->format('Y-m');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('orders.timezone'))->startOfDay();
    }

    public function isLocked(string $period): bool
    {
        return AccountingPeriod::query()->where('period', $period)->where('status', PeriodStatus::Locked)->exists();
    }

    public function assertOpen(DateTimeInterface|string $date): void
    {
        $period = self::of($date);

        if ($this->isLocked($period)) {
            throw new AccountingActionException('period_locked', ['period' => $period]);
        }
    }
}
