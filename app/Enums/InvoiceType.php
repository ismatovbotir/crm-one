<?php

namespace App\Enums;

/**
 * Invoice documents (Spec 06 6.6, DEC-6, section 25): the CRM issues proformas (B2B) and sales
 * receipts (retail); tax documents are issued in 1C/e-invoicing and registered.
 */
enum InvoiceType: string
{
    case Proforma = 'proforma';
    case TaxInvoice = 'tax_invoice';
    case Act = 'act';
    case Waybill = 'waybill';
    case SalesReceipt = 'receipt';

    public function isTaxDocument(): bool
    {
        return in_array($this, self::taxDocuments(), true);
    }

    /**
     * @return list<self>
     */
    public static function taxDocuments(): array
    {
        return [self::TaxInvoice, self::Act, self::Waybill];
    }

    public function label(): string
    {
        return __('accounting.invoice_types.'.$this->value);
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
