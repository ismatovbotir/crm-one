<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a requisition (Spec 10 §3): a catalog item, or a category-level need with a free-text
 * description that our sales maps to an item (`mapped_item_id`). Quantity only, no prices.
 */
#[Fillable(['requisition_id', 'kind', 'item_id', 'category_id', 'description', 'quantity', 'unit', 'needed_by', 'mapped_item_id', 'source_line_id', 'position'])]
class RequisitionLine extends Model
{
    public const KIND_ITEM = 'item';

    public const KIND_CATEGORY = 'category';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'needed_by' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function mappedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'mapped_item_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function isCategoryLine(): bool
    {
        return $this->kind === self::KIND_CATEGORY;
    }

    /** The item the offer will quote: the chosen item, or our mapping of a category line. */
    public function offerItem(): ?Item
    {
        return $this->isCategoryLine() ? $this->mappedItem : $this->item;
    }

    public function label(): string
    {
        return $this->isCategoryLine()
            ? (string) ($this->category?->portalName() ?? '—').': '.$this->description
            : (string) $this->item?->name;
    }
}
