<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\SyncsWithOneC;
use Database\Factories\CategoryFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item category. Groups mirrored from 1C (`external_id` set) keep whatever depth 1C has
 * (Spec 02 BR-I.13); CRM-created categories (no `external_id`) are limited to MAX_DEPTH levels
 * (Spec 01 section 6.6). No category may form a cycle. `sort_order` is CRM-local.
 */
#[Fillable(['parent_id', 'name', 'sort_order', 'is_active', 'external_id', 'external_code', 'synced_at', 'portal_visible', 'portal_names', 'portal_sort'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, LogsActivity, SyncsWithOneC;

    /** Depth limit for CRM-created categories (Spec 01 section 6.6). */
    public const MAX_DEPTH = 3;

    /** Safety cap when walking the tree, so corrupt data can never loop forever. */
    public const MAX_TRAVERSAL_DEPTH = 32;

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->guardTree();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portal_visible' => 'boolean',
            'portal_names' => 'array',
            'portal_sort' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @param  Builder<Category>  $query
     */
    public function scopeRoots(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * Ids of this category and all its descendants (for "category incl. sub-categories" filters).
     *
     * @return list<int>
     */
    public static function withDescendantIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $frontier = [$categoryId];

        for ($level = 1; $level <= self::MAX_TRAVERSAL_DEPTH && $frontier !== []; $level++) {
            $frontier = array_values(array_diff(self::query()->whereIn('parent_id', $frontier)->pluck('id')->all(), $ids));
            $ids = [...$ids, ...$frontier];
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * Depth of the category (root = 1) following parent_id.
     */
    public function depth(): int
    {
        $depth = 1;
        $parentId = $this->parent_id;
        $seen = [$this->id];

        while ($parentId !== null) {
            if (in_array($parentId, $seen, true)) {
                throw new DomainException(__('catalog.categories.errors.cycle'));
            }

            $seen[] = $parentId;
            $depth++;
            $parentId = self::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /**
     * Height of the subtree below this category (leaf = 0).
     */
    protected function subtreeHeight(): int
    {
        if (! $this->exists) {
            return 0;
        }

        $height = 0;
        $frontier = [$this->id];

        while (($frontier = self::query()->whereIn('parent_id', $frontier)->pluck('id')->all()) !== []) {
            if (in_array($this->id, $frontier, true) || $height > self::MAX_TRAVERSAL_DEPTH) {
                throw new DomainException(__('catalog.categories.errors.cycle'));
            }

            $height++;
        }

        return $height;
    }

    protected function guardTree(): void
    {
        if ($this->exists && $this->parent_id !== null && $this->parent_id === $this->id) {
            throw new DomainException(__('catalog.categories.errors.cycle'));
        }

        if ($this->exists && $this->parent_id !== null && in_array($this->parent_id, self::withDescendantIds($this->id), true)) {
            throw new DomainException(__('catalog.categories.errors.cycle'));
        }

        $depth = $this->depth();
        $height = $this->subtreeHeight();

        if ($this->isCrmCreated() && $depth + $height > self::MAX_DEPTH) {
            throw new DomainException(__('catalog.categories.errors.depth', ['max' => self::MAX_DEPTH]));
        }
    }

    /**
     * A category created in the CRM (no 1C GUID); only these obey MAX_DEPTH.
     */
    public function isCrmCreated(): bool
    {
        return ! $this->isLinkedToOneC();
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['external_hash', 'synced_at', 'last_seen_snapshot_id'];
    }

    /**
     * Name shown in the portal (Spec 10 D10-4): the CRM portal name in the language, else the 1C name.
     */
    public function portalName(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $names = (array) ($this->portal_names ?? []);

        return (string) (filled($names[$locale] ?? null) ? $names[$locale] : $this->name);
    }
}
