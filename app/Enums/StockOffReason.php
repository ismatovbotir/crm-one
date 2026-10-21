<?php

namespace App\Enums;

/**
 * Why stock leaves the books on a stock-off act (Spec 06 section 27.2). `other` needs a note.
 */
enum StockOffReason: string
{
    case DamagedAtReceipt = 'damaged_at_receipt';
    case DamagedInStorage = 'damaged_in_storage';
    case DamagedInTransit = 'damaged_in_transit';
    case Lost = 'lost';
    case Theft = 'theft';
    case Expired = 'expired';
    case QualityRejection = 'quality_rejection';
    case InternalUse = 'internal_use';
    case Sample = 'sample';
    case ReturnRejected = 'return_rejected';
    case Other = 'other';

    public function needsNote(): bool
    {
        return $this === self::Other;
    }

    public function label(): string
    {
        return __('stock_off.reasons.'.$this->value);
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
