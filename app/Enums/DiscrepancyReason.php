<?php

namespace App\Enums;

/**
 * Why received quantity differs from expected (Spec 06 21.4).
 */
enum DiscrepancyReason: string
{
    case Short = 'short';
    case Over = 'over';
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case Other = 'other';

    public function label(): string
    {
        return __('stock.discrepancy_reasons.'.$this->value);
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
