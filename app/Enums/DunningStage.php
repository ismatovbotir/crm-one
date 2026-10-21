<?php

namespace App\Enums;

/**
 * Payment reminder stages (Spec 06 BR-AC.17, 7.14): `pre_due` three days before the due date, then
 * one stage per overdue threshold. A schedule item fires only the highest stage it has reached, so
 * a long-overdue item first seen today gets one reminder, not five.
 */
enum DunningStage: string
{
    case PreDue = 'pre_due';
    case Due1 = 'due_1';
    case Due7 = 'due_7';
    case Due14 = 'due_14';
    case Due30 = 'due_30';

    /** Days before the due date that start the `pre_due` stage. */
    public const PRE_DUE_DAYS = 3;

    /** Overdue days at which the stage starts (pre_due: days before due, shown negative). */
    public function days(): int
    {
        return match ($this) {
            self::PreDue => -self::PRE_DUE_DAYS,
            self::Due1 => 1,
            self::Due7 => 7,
            self::Due14 => 14,
            self::Due30 => 30,
        };
    }

    public function isOverdue(): bool
    {
        return $this !== self::PreDue;
    }

    /**
     * The stage reached by a schedule item `$daysToDue` days before (positive) or after (negative)
     * its due date; null when nothing is due yet.
     */
    public static function reached(int $daysOverdue): ?self
    {
        $stage = null;

        foreach (self::cases() as $case) {
            if ($daysOverdue >= $case->days()) {
                $stage = $case;
            }
        }

        return $stage;
    }

    public function label(): string
    {
        return __('accounting.dunning.stages.'.$this->value);
    }
}
