<?php

namespace App\Enums;

/**
 * How a bid reached the CRM (Spec 08 DEC-1): employee-entered with evidence, unique link, partner portal.
 */
enum BidChannel: string
{
    case Employee = 'employee';
    case Link = 'link';
    case Portal = 'portal';

    public function label(): string
    {
        return __('tenders.bid_channel.'.$this->value);
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
