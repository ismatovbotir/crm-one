<?php

namespace App\Enums;

/**
 * Invoice states (Spec 06 6.6): draft → issued → voided.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Voided = 'voided';

    public function label(): string
    {
        return __('accounting.invoice_status.'.$this->value);
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
