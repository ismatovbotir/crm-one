<?php

namespace App\Models;

use App\Documents\DocumentFormatter;
use App\Enums\AlternativeReason;
use App\Enums\OptionGroupKind;
use App\Enums\StockBand;
use App\Support\ImageEmbed;
use Database\Factories\OfferLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Offer line with the item snapshot taken when it was added (Spec 05 7.4). Amount columns are
 * written only by RecalculateOffer. `internal_note` is never shown to the customer.
 *
 * @property string $quantity
 * @property StockBand|null $stock_band
 * @property AlternativeReason|null $alternative_reason
 */
#[Fillable([
    'offer_id', 'option_group_id', 'stable_key', 'position', 'option_label', 'is_recommended', 'item_id',
    'item_external_id', 'sku', 'article', 'accountant_code', 'class_code', 'package_code', 'is_serialized', 'item_image_id', 'image_path', 'name',
    'description', 'unit_id', 'unit_code', 'unit_name', 'unit_precision', 'tax_rate_bp', 'is_vat_exempt',
    'price_source', 'list_unit_price_minor', 'cost_price_minor', 'quantity', 'unit_price_minor', 'discount_bp',
    'lead_time_days', 'stock_band', 'stock_available_qty', 'stock_checked_at', 'alternative_reason',
    'alternative_reason_note', 'comparison_notes', 'internal_note',
])]
class OfferLine extends Model
{
    /** @use HasFactory<OfferLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'stock_available_qty' => 'decimal:3',
            'is_recommended' => 'boolean',
            'is_serialized' => 'boolean',
            'is_vat_exempt' => 'boolean',
            'position' => 'integer',
            'unit_precision' => 'integer',
            'tax_rate_bp' => 'integer',
            'list_unit_price_minor' => 'integer',
            'cost_price_minor' => 'integer',
            'unit_price_minor' => 'integer',
            'discount_bp' => 'integer',
            'effective_discount_bp' => 'integer',
            'line_discount_minor' => 'integer',
            'line_net_minor' => 'integer',
            'line_vat_minor' => 'integer',
            'line_gross_minor' => 'integer',
            'lead_time_days' => 'integer',
            'stock_band' => StockBand::class,
            'stock_checked_at' => 'immutable_datetime',
            'alternative_reason' => AlternativeReason::class,
        ];
    }

    /**
     * @return BelongsTo<Offer, $this>
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<OfferOptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OfferOptionGroup::class, 'option_group_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    public function kind(): OptionGroupKind
    {
        return $this->option_group_id === null ? OptionGroupKind::Fixed : ($this->group?->kind ?? OptionGroupKind::Optional);
    }

    /**
     * Net cost of the line (cost basis x quantity), or null when the cost is unknown.
     */
    public function costNetMinor(): ?int
    {
        return $this->cost_price_minor === null ? null : (int) DocumentFormatter::round(bcmul((string) $this->quantity, (string) $this->cost_price_minor, 6), 0);
    }

    /** Sold below the cost basis (needs manager approval, rev. 4). */
    public function isBelowCost(): bool
    {
        $cost = $this->costNetMinor();

        return $cost !== null && $this->line_net_minor < $cost;
    }

    public function isFixed(): bool
    {
        return $this->option_group_id === null;
    }

    /**
     * @return BelongsTo<ItemImage, $this>
     */
    public function itemImage(): BelongsTo
    {
        return $this->belongsTo(ItemImage::class);
    }

    /**
     * Picture shown for this line (Spec 05 section 16): the snapshot taken at sending, else the
     * referenced item picture; null = placeholder.
     */
    public function imageUrl(): ?string
    {
        if ($this->image_path !== null) {
            return route('media.offer-line-image', ['line' => $this->id]);
        }

        return $this->itemImage?->url('medium');
    }

    /** Data URI for prints (snapshot, else the referenced picture), null = placeholder. */
    public function imageDataUri(): ?string
    {
        return ImageEmbed::dataUri($this->image_path ?? $this->itemImage?->medium_path);
    }
}
