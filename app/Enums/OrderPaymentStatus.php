<?php

namespace App\Enums;

/**
 * Computed payment state of an order (Spec 06 section 6.3), stored on orders.payment_status.
 */
enum OrderPaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return __('orders.payment_status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Unpaid => ['outline', null],
            self::PartiallyPaid => ['silver', 'clock'],
            self::Paid => ['dark', 'check'],
            self::Overdue => ['red', 'warning'],
        };
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
