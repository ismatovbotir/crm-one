<?php

namespace App\Enums;

/**
 * Spot purchase/shipment, lane contract (period, volume) or framework.
 */
enum TenderKind: string
{
    case Spot = 'spot';
    case LaneContract = 'lane_contract';
    case Framework = 'framework';

    public function label(): string
    {
        return __('tenders.kind.'.$this->value);
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
