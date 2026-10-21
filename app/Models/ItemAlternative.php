<?php

namespace App\Models;

use App\Enums\AlternativeReason;
use App\Enums\ItemAlternativeRelation;
use App\Models\Concerns\LogsActivity;
use Database\Factories\ItemAlternativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-local catalog analogue link (Spec 05 7.10, DEC-11). Items themselves stay read-only.
 *
 * @property ItemAlternativeRelation $relation
 * @property AlternativeReason|null $default_reason
 */
#[Fillable(['item_id', 'alternative_item_id', 'relation', 'is_mutual', 'default_reason', 'note', 'source', 'status', 'created_by', 'approved_by'])]
class ItemAlternative extends Model
{
    /** @use HasFactory<ItemAlternativeFactory> */
    use HasFactory, LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => ItemAlternativeRelation::class,
            'default_reason' => AlternativeReason::class,
            'is_mutual' => 'boolean',
        ];
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
    public function alternative(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'alternative_item_id')->withTrashed();
    }
}
