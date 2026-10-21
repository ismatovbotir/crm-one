<?php

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

/**
 * Whole calendar days between two Y-m-d dates, timezone independent (due dates are calendar
 * dates in Asia/Tashkent).
 */
final class CarbonDays
{
    public static function between(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), false);
    }
}
