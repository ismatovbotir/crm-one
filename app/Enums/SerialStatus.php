<?php

namespace App\Enums;

/**
 * Serial number states (Spec 06 21.4). Only `available` serials can be sold; nothing deletes a serial.
 */
enum SerialStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Returned = 'returned';
    case Quarantined = 'quarantined';
    case WrittenOff = 'written_off';

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Available => ['dark', 'check'],
            self::Reserved => ['silver', 'clock'],
            self::Sold => ['outline', 'orders'],
            self::Returned, self::Quarantined => ['danger-outline', 'warning'],
            self::WrittenOff => ['outline', 'close'],
        };
    }

    /** Counted against the 1C quantity in reconciliation (Spec 06 21.11). */
    public function isOnHand(): bool
    {
        return in_array($this, [self::Available, self::Reserved, self::Returned, self::Quarantined], true);
    }

    public function label(): string
    {
        return __('stock.serial_status.'.$this->value);
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
