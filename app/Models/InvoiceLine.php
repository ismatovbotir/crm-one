<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice line: snapshot copied from the order line (price, VAT, codes) with the invoiced
 * quantity (Spec 06 7.10, DEC-15).
 *
 * @property string $quantity
 */
#[Fillable([
    'invoice_id', 'order_line_id', 'delivery_line_id', 'position', 'item_external_id', 'sku', 'name', 'unit_code', 'unit_name',
    'tax_rate_bp', 'is_vat_exempt', 'accountant_code', 'class_code', 'package_code', 'quantity', 'unit_price_minor',
    'discount_bp', 'line_net_minor', 'line_vat_minor', 'line_gross_minor',
])]
class InvoiceLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_vat_exempt' => 'boolean',
            'tax_rate_bp' => 'integer',
            'discount_bp' => 'integer',
            'unit_price_minor' => 'integer',
            'line_net_minor' => 'integer',
            'line_vat_minor' => 'integer',
            'line_gross_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }
}
