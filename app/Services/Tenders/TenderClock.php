<?php

namespace App\Services\Tenders;

use Carbon\CarbonImmutable;

/**
 * Deadline arithmetic in the business timezone (BR-T.2, BR-T.13): stored UTC, entered and shown
 * in Asia/Tashkent (UTC+5); working days are Monday-Friday.
 */
final class TenderClock
{
    public static function timezone(): string
    {
        return (string) config('tenders.timezone');
    }

    public static function label(): string
    {
        return (string) config('tenders.timezone_label');
    }

    /**
     * Parse a local "Y-m-d H:i" / "Y-m-d\TH:i" value into UTC; null for empty or invalid input.
     */
    public static function parseLocal(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(str_replace('T', ' ', $value), self::timezone())->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Value for a datetime-local input. */
    public static function toLocalInput(?CarbonImmutable $moment): string
    {
        return $moment === null ? '' : $moment->setTimezone(self::timezone())->format('Y-m-d\TH:i');
    }

    /**
     * The moment $days working days after $from (same local time of day).
     */
    public static function addWorkingDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $local = $from->setTimezone(self::timezone());

        while ($days > 0) {
            $local = $local->addDay();

            if (! $local->isWeekend()) {
                $days--;
            }
        }

        return $local->utc();
    }
}
