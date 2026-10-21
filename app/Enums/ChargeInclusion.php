<?php

namespace App\Enums;

/**
 * Whether a bid component is included in the price, not included (owner estimate) or not applicable.
 */
enum ChargeInclusion: string
{
    case Included = 'included';
    case NotIncluded = 'not_included';
    case Na = 'na';

    public function label(): string
    {
        return __('tenders.inclusion.'.$this->value);
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
