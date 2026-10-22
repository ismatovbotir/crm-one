<?php

namespace App\Models;

use App\Documents\DocumentFormatter;
use App\Enums\ItemType;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SyncsWithOneC;
use App\Support\Money;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catalog item mirrored from 1C (Spec 01 section 6.7 as amended by Spec 02): read-only in the UI
 * except the CRM-local `min_order_qty` and `order_step`, and the shared accounting codes and
 * serial-tracking flag (Spec 02 BR-I.13a/b). Pricing (rev. 4): exactly one sale (out) price and one
 * purchase (in) price, each with its currency, in minor units, both from 1C; the stock cost price is
 * the weighted average of the stock rows' cost.
 *
 * @property ItemType $type
 * @property string|null $min_order_qty
 * @property string|null $order_step
 */
#[Fillable([
    'sku', 'article', 'barcode', 'name', 'description', 'type', 'category_id', 'unit_id', 'tax_rate_bp', 'is_vat_exempt',
    'min_order_qty', 'order_step', 'is_active', 'external_id', 'external_code', 'synced_at',
    'accountant_code', 'class_code', 'package_code', 'is_serialized', 'warranty_months',
    'hs_code', 'gross_weight_kg', 'net_weight_kg', 'volume_m3', 'country_of_origin',
    'sale_price_minor', 'sale_currency', 'purchase_price_minor', 'purchase_currency', 'prices_include_vat',
    'portal_visible', 'needs_review', 'review_reason',
])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory, LogsActivity, SoftDeletes, SyncsWithOneC;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_serialized' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'needs_review' => 'boolean',
            'type' => ItemType::class,
            'tax_rate_bp' => 'integer',
            'is_vat_exempt' => 'boolean',
            'is_serialized' => 'boolean',
            'sale_price_minor' => 'integer',
            'purchase_price_minor' => 'integer',
            'prices_include_vat' => 'boolean',
            'min_order_qty' => 'decimal:3',
            'order_step' => 'decimal:3',
            'is_active' => 'boolean',
            'gross_weight_kg' => 'decimal:4',
            'net_weight_kg' => 'decimal:4',
            'volume_m3' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The single sale (out) price, or null when 1C has not sent one.
     */
    public function salePrice(): ?Money
    {
        return $this->sale_price_minor === null || blank($this->sale_currency) ? null : Money::of($this->sale_price_minor, $this->sale_currency);
    }

    /**
     * The single purchase (in) price, or null.
     */
    public function purchasePrice(): ?Money
    {
        return $this->purchase_price_minor === null || blank($this->purchase_currency) ? null : Money::of($this->purchase_price_minor, $this->purchase_currency);
    }

    /**
     * Stock cost price per base unit: total stock cost / total quantity over the warehouses that
     * report a cost for a positive quantity (weighted average), rounded half-up to the minor unit.
     * Rows in another currency than the first costed row are ignored (no FX conversion).
     */
    public function stockCostPrice(): ?Money
    {
        $rows = $this->stocks->filter(fn (ItemStock $stock): bool => $stock->cost_minor !== null && filled($stock->cost_currency) && bccomp((string) $stock->quantity, '0', 3) > 0);

        if ($rows->isEmpty()) {
            return null;
        }

        $currency = (string) $rows->first()->cost_currency;
        $rows = $rows->where('cost_currency', $currency);
        $quantity = $rows->reduce(fn (string $sum, ItemStock $stock): string => bcadd($sum, (string) $stock->quantity, 3), '0');
        $cost = $rows->sum('cost_minor');

        return Money::of((int) DocumentFormatter::round(bcdiv((string) $cost, $quantity, 6), 0), $currency);
    }

    /**
     * Cost basis for margins and the below-cost check in a currency: the stock cost price, else the
     * purchase price; null when neither is known in that currency.
     */
    public function costBasis(string $currency): ?Money
    {
        foreach ([$this->stockCostPrice(), $this->purchasePrice()] as $price) {
            if ($price !== null && $price->currency === strtoupper($currency)) {
                return $price;
            }
        }

        return null;
    }

    /**
     * CRM-local analogue links from this item (Spec 05 7.10).
     *
     * @return HasMany<ItemAlternative, $this>
     */
    public function alternatives(): HasMany
    {
        return $this->hasMany(ItemAlternative::class);
    }

    /**
     * CRM-owned pictures, main first (Spec 01 section 15).
     *
     * @return HasMany<ItemImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ItemImage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<ItemImage, $this>
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(ItemImage::class)->where('position', 0);
    }

    /**
     * @return HasMany<ItemStock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(ItemStock::class);
    }

    /**
     * Items that can be put on new offers: active, not archived, not deleted in 1C (BR-12, AC-2.7).
     *
     * @param  Builder<Item>  $query
     */
    public function scopeSellable(Builder $query): void
    {
        $query->where('is_active', true)->whereNull('external_deleted_at');
    }

    public function isSellable(): bool
    {
        return $this->is_active && ! $this->trashed() && ! $this->isDeletedInOneC();
    }

    /**
     * Codes among $skus that more than one item carries (1C owns the code and may repeat it;
     * identity is the 1C GUID). Used for an informational warning only.
     *
     * @param  list<string>  $skus
     * @return list<string>
     */
    public static function duplicateSkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return static::withTrashed()
            ->whereIn('sku', array_values(array_unique($skus)))
            ->groupBy('sku')
            ->havingRaw('count(*) > 1')
            ->pluck('sku')
            ->all();
    }

    /**
     * Number of other items (incl. archived) with the same 1C code.
     */
    public function sameSkuCount(): int
    {
        return static::withTrashed()->where('sku', $this->sku)->whereKeyNot($this->getKey())->count();
    }

    /**
     * VAT rate as a percentage string, e.g. 1200 bp => "12".
     */
    public function vatPercent(): string
    {
        return rtrim(rtrim(bcdiv((string) $this->tax_rate_bp, '100', 2), '0'), '.');
    }

    /**
     * Sum of available stock (quantity - reserved, floored at 0 per warehouse) as a decimal string.
     */
    public function availableStock(): string
    {
        return $this->stocks->reduce(fn (string $sum, ItemStock $stock): string => bcadd($sum, $stock->available(), 3), '0.000');
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        if (in_array('tax_rate_bp', $changedKeys, true)) {
            return 'price_changed';
        }

        return $changedKeys === ['is_active'] ? ($this->is_active ? 'activated' : 'deactivated') : 'updated';
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        // No price history (2026-10-19): only the current purchase/sale price is kept; changes are not logged.
        return ['external_hash', 'synced_at', 'last_seen_snapshot_id', 'external_updated_at', 'sale_price_minor', 'sale_currency', 'purchase_price_minor', 'purchase_currency', 'prices_include_vat'];
    }
}
