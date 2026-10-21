<?php

namespace App\Enums;

/**
 * How a payment was made (Spec 06 7.11).
 */
enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';

    /** Bank transfers and cards need our bank account and a reference number (BR-AC.5). */
    public function needsBankDetails(): bool
    {
        return $this !== self::Cash;
    }

    public function label(): string
    {
        return __('accounting.methods.'.$this->value);
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
