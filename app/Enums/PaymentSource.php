<?php

namespace App\Enums;

/**
 * Who recorded a payment (Spec 06 7.11): the CRM or 1C (book of record).
 */
enum PaymentSource: string
{
    case Crm = 'crm';
    case Onec = 'onec';

    public function label(): string
    {
        return __('accounting.sources.'.$this->value);
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
