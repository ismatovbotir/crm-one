<?php

namespace App\Enums;

/**
 * Where received goods come from (Spec 06 21.4). Purchase orders and shipments link in with tenders/transport.
 */
enum ReceiptSource: string
{
    case SupplierDelivery = 'supplier_delivery';
    case OpeningBalance = 'opening_balance';
    case CustomerReturn = 'customer_return';
    case PurchaseOrder = 'purchase_order';
    case Shipment = 'shipment';

    /** Serials added from the catalogue "Add / import serials" dialog (manual-receipt reason). */
    case ManualRegistration = 'manual_registration';

    public function label(): string
    {
        return __('stock.receipt_sources.'.$this->value);
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
