<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CRM-owned picture of an item (Spec 01 section 15): position 0 is the main picture. Files are
 * re-encoded JPEGs on the private disk (full ≤ 1600 px, medium 320 px, thumb 96 px) and served by
 * MediaController; 1C never sends or changes them.
 */
#[Fillable(['item_id', 'position', 'path', 'medium_path', 'thumb_path', 'width', 'height', 'size_bytes', 'sha256', 'created_by'])]
class ItemImage extends Model
{
    use HasUuids;

    public const DISK = 'local';

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isMain(): bool
    {
        return $this->position === 0;
    }

    public function pathFor(string $variant): string
    {
        return match ($variant) {
            'thumb' => $this->thumb_path,
            'medium' => $this->medium_path,
            default => $this->path,
        };
    }

    public function url(string $variant = 'medium'): string
    {
        return route('media.item-image', ['image' => $this->uuid, 'variant' => $variant]);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class)->withTrashed();
    }
}
