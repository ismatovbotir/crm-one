<?php

namespace App\Enums;

/**
 * Accounting period state (Spec 06 7.14).
 */
enum PeriodStatus: string
{
    case Open = 'open';
    case Locked = 'locked';

    public function label(): string
    {
        return __('accounting.period_status.'.$this->value);
    }
}
