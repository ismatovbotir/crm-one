<?php

namespace App\Enums;

/**
 * Abilities of a 1C integration token (Spec 02 section 3; customers:* added in rev. 3). `ping` is implicit for any valid token.
 */
enum IntegrationAbility: string
{
    case ItemsWrite = 'items:write';
    case StockWrite = 'stock:write';
    case BatchesRead = 'batches:read';
    case CustomersRead = 'customers:read';
    case CustomersWrite = 'customers:write';

    /** Spec 06 DEC-14: read the order feed of the CRM outbox (orders, agreements, invoices). */
    case OrdersRead = 'orders:read';

    /** Spec 06 DEC-14: read the payment feed of the CRM outbox. */
    case PaymentsRead = 'payments:read';

    /** Spec 06 9.3: push bank/cash receipts and refunds posted in 1C. */
    case PaymentsWrite = 'payments:write';

    /** Spec 06 21.9: read the warehouse feed (goods receipts) of the CRM outbox. */
    case WarehouseRead = 'warehouse:read';

    /** Spec 06 26: push goods receipts created in 1C (the CRM stock manager completes them). */
    case WarehouseWrite = 'warehouse:write';

    public function label(): string
    {
        return __('integration.abilities.'.str_replace(':', '_', $this->value));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
