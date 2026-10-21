<?php

namespace App\Enums;

/**
 * Procurement method (purchase-tender skill section 1). Reverse auctions are roadmap.
 */
enum TenderMethod: string
{
    case Rfq = 'rfq';
    case Rfp = 'rfp';
    case SingleSource = 'single_source';

    public function label(): string
    {
        return __('tenders.method.'.$this->value);
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
