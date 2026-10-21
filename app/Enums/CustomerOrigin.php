<?php

namespace App\Enums;

/**
 * Where a customer record was created (Spec 02 section 11.5).
 */
enum CustomerOrigin: string
{
    case Crm = 'crm';
    case Onec = 'onec';

    public function label(): string
    {
        return __('customers.origins.'.$this->value);
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
