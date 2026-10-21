<?php

namespace App\Enums;

/**
 * Stock adjustment kinds (Spec 06 sections 21.4 and 27.1): what stays an adjustment once write-offs
 * became stock-off acts. A status change after inspection and typo fixes live on the serial tools.
 */
enum StockAdjustmentType: string
{
    /** New serials appear (found stock, or a written-off serial coming back). */
    case Found = 'found';

    /** A posted delivery note carried the wrong serial: replace it. */
    case SerialCorrection = 'serial_correction';

    /** Quantity of a non-serialized item is corrected (informational to 1C). */
    case QtyCorrection = 'qty_correction';

    public function label(): string
    {
        return __('stock_adjustments.types.'.$this->value);
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
