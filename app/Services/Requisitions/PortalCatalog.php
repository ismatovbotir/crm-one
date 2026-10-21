<?php

namespace App\Services\Requisitions;

use App\Models\CatalogVisibilityRule;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Partner;
use App\Support\BusinessSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Price-free portal catalog (Spec 10 D10-1..D10-3): which categories and items an organization
 * may see (global "visible in portal" flags, per-customer allow/deny rules, sub-categories inherit
 * their parent) and availability as words. Never selects or returns price, cost or stock values.
 */
class PortalCatalog
{
    /** Columns the portal may read from items (no price or cost columns). */
    public const ITEM_COLUMNS = ['id', 'sku', 'article', 'name', 'description', 'category_id', 'unit_id', 'type'];

    /**
     * Visible category ids for the organization.
     *
     * @return list<int>
     */
    public function visibleCategoryIds(Partner $organization): array
    {
        $categories = Category::query()->where('is_active', true)->get(['id', 'parent_id', 'portal_visible']);
        $rules = $this->rules($organization);
        $allowed = $rules->where('mode', CatalogVisibilityRule::ALLOW)->pluck('category_id')->filter()->all();
        $denied = $rules->where('mode', CatalogVisibilityRule::DENY)->pluck('category_id')->filter()->all();
        $allowListMode = $rules->where('mode', CatalogVisibilityRule::ALLOW)->isNotEmpty();
        $byId = $categories->keyBy('id');
        $visible = [];

        foreach ($categories as $category) {
            $chain = $this->chain($category, $byId);
            $explicitAllow = array_intersect($chain, $allowed) !== [];

            if (array_intersect($chain, $denied) !== []) {
                continue;
            }

            $globallyHidden = $categories->whereIn('id', $chain)->contains(fn (Category $c): bool => ! $c->portal_visible);

            if ($globallyHidden && ! $explicitAllow) {
                continue;
            }

            // Allow-list mode: only explicitly allowed categories (and their sub-categories); allowed items are added in items().
            if ($allowListMode && ! $explicitAllow) {
                continue;
            }

            $visible[] = (int) $category->id;
        }

        return $visible;
    }

    /**
     * Items the organization may see in the portal (price columns never selected).
     *
     * @return Builder<Item>
     */
    public function items(Partner $organization): Builder
    {
        $rules = $this->rules($organization);
        $categoryIds = $this->visibleCategoryIds($organization);
        $allowedItems = $rules->where('mode', CatalogVisibilityRule::ALLOW)->pluck('item_id')->filter()->values()->all();
        $deniedItems = $rules->where('mode', CatalogVisibilityRule::DENY)->pluck('item_id')->filter()->values()->all();
        $allowListMode = $rules->where('mode', CatalogVisibilityRule::ALLOW)->isNotEmpty();

        return Item::query()->select(self::ITEM_COLUMNS)
            ->where('is_active', true)->whereNull('external_deleted_at')
            ->when($deniedItems !== [], fn ($q) => $q->whereNotIn('id', $deniedItems))
            ->where(function ($q) use ($categoryIds, $allowedItems, $allowListMode): void {
                $q->where(function ($visible) use ($categoryIds, $allowListMode): void {
                    $visible->where('portal_visible', true)->where(fn ($c) => $c->whereIn('category_id', $categoryIds)->when(! $allowListMode, fn ($n) => $n->orWhereNull('category_id')));
                });

                if ($allowedItems !== []) {
                    $q->orWhereIn('id', $allowedItems);
                }
            });
    }

    public function isVisible(Partner $organization, int $itemId): bool
    {
        return $this->items($organization)->whereKey($itemId)->exists();
    }

    /**
     * Availability words for items (D10-2): in_stock / on_request, plus a band when enabled.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{status: string, band: string|null}>
     */
    public function availability(array $itemIds): array
    {
        $free = ItemStock::query()->whereIn('item_id', $itemIds)->toBase()->selectRaw('item_id, sum(quantity - reserved) as free')->groupBy('item_id')->pluck('free', 'item_id');
        $showBand = BusinessSettings::bool('requisitions.show_stock_band');
        $result = [];

        foreach ($itemIds as $id) {
            $quantity = (float) ($free[$id] ?? 0);
            $result[$id] = [
                'status' => $quantity > 0 ? 'in_stock' : 'on_request',
                'band' => $showBand && $quantity > 0 ? ($quantity <= 10 ? '1-10' : ($quantity <= 50 ? '11-50' : '50+')) : null,
            ];
        }

        return $result;
    }

    /**
     * @return Collection<int, CatalogVisibilityRule>
     */
    protected function rules(Partner $organization): Collection
    {
        return CatalogVisibilityRule::query()->where('partner_id', $organization->id)->get();
    }

    /**
     * Ids of the category and its ancestors.
     *
     * @param  Collection<int, Category>  $byId
     * @return list<int>
     */
    protected function chain(Category $category, Collection $byId): array
    {
        $ids = [];
        $current = $category;

        for ($depth = 0; $current !== null && $depth < 20; $depth++) {
            $ids[] = (int) $current->id;
            $current = $current->parent_id !== null ? $byId->get($current->parent_id) : null;
        }

        return $ids;
    }
}
