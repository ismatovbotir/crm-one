<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-customer portal catalog rule (Spec 10 D10-3): `deny` hides a category (with its
 * sub-categories) or an item; any `allow` switches the customer to allow-list mode.
 */
#[Fillable(['partner_id', 'category_id', 'item_id', 'mode', 'created_by'])]
class CatalogVisibilityRule extends Model
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
