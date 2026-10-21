<?php

namespace App\Enums;

/**
 * Kinds of change an order amendment can hold (Spec 06 section 6.4). Dates, address and payment
 * terms have their own screens (order details, payment terms). `price_change` changes the
 * discount of a line: unit prices come from the price list and are never edited by hand.
 */
enum OrderAmendmentType: string
{
    case QtyChange = 'qty_change';
    case PriceChange = 'price_change';
    case AddLine = 'add_line';
    case RemoveLine = 'remove_line';
    case CloseShort = 'close_short';

    public function label(): string
    {
        return __('order_amendments.types.'.$this->value);
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
