<?php

namespace App\Enums;

/**
 * Payment schedule item kinds (Spec 06 7.7).
 */
enum ScheduleItemKind: string
{
    case Prepayment = 'prepayment';
    case OnDelivery = 'on_delivery';
    case Milestone = 'milestone';

    public function label(): string
    {
        return __('accounting.schedule_kinds.'.$this->value);
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
