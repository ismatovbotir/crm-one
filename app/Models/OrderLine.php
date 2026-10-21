<?php

namespace App\Models;

use App\Support\ImageEmbed;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order line: snapshot of an offer fixed or selected line (Spec 06 7.3). Amounts and codes are
 * copied verbatim (DEC-15); delivered/invoiced/cancelled quantities are maintained by documents.
 *
 * @property string $quantity
 * @property string $delivered_qty
 * @property string $invoiced_qty
 * @property string $cancelled_qty
 */
#[Fillable([
    'order_id', 'position', 'offer_line_id', 'offer_option_group_id', 'option_label', 'group_title', 'item_id',
    'item_external_id', 'sku', 'name', 'unit_id', 'unit_code', 'unit_name', 'unit_precision', 'tax_rate_bp', 'is_vat_exempt',
    'accountant_code', 'class_code', 'package_code', 'is_serialized', 'image_path', 'quantity', 'unit_price_minor', 'discount_bp',
    'line_net_minor', 'line_vat_minor', 'line_gross_minor', 'cost_price_minor', 'warehouse_id', 'lead_time_days',
])]
class OrderLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'delivered_qty' => 'decimal:3',
            'invoiced_qty' => 'decimal:3',
            'cancelled_qty' => 'decimal:3',
            'returned_qty' => 'decimal:3',
            'is_vat_exempt' => 'boolean',
            'is_serialized' => 'boolean',
            'backorder' => 'boolean',
            'unit_price_minor' => 'integer',
            'line_net_minor' => 'integer',
            'line_vat_minor' => 'integer',
            'line_gross_minor' => 'integer',
            'cost_price_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'discount_bp' => 'integer',
            'unit_precision' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /** Quantity still to deliver: quantity − delivered − cancelled. */
    public function openQuantity(): string
    {
        return bcsub(bcsub((string) $this->quantity, (string) $this->delivered_qty, Quantity::SCALE), (string) $this->cancelled_qty, Quantity::SCALE);
    }

    public function isFullyDelivered(): bool
    {
        return bccomp($this->openQuantity(), '0', Quantity::SCALE) <= 0;
    }

    /** Picture snapshot copied from the sent offer (Spec 05 section 16), null = placeholder. */
    public function imageUrl(): ?string
    {
        return $this->image_path !== null ? route('media.order-line-image', ['line' => $this->id]) : null;
    }

    public function imageDataUri(): ?string
    {
        return ImageEmbed::dataUri($this->image_path);
    }
}
