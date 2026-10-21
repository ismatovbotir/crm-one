<?php

namespace App\Enums;

/**
 * How a serial entered the registry (Spec 06 21.5).
 */
enum SerialSource: string
{
    case Receipt = 'receipt';
    case OpeningBalance = 'opening_balance';
    case Found = 'found';
    case Return = 'return';
    case ManualOverride = 'manual_override';

    public function label(): string
    {
        return __('stock.serial_sources.'.$this->value);
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
