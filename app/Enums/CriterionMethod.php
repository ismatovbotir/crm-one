<?php

namespace App\Enums;

/**
 * How a criterion is scored 0-10 (transport-tender skill section 5).
 */
enum CriterionMethod: string
{
    case AutoLowestCost = 'auto_lowest_cost';
    case AutoFastest = 'auto_fastest';
    case AutoPayment = 'auto_payment';
    case Rating = 'rating';
    case Manual = 'manual';

    public function label(): string
    {
        return __('tenders.criterion_method.'.$this->value);
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
