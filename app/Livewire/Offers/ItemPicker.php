<?php

namespace App\Livewire\Offers;

use App\Actions\Offers\AddOfferLine;
use App\Enums\StockBand;
use App\Models\Item;
use App\Models\ItemAlternative;
use App\Models\Offer;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Pricing\Exceptions\ItemNotSellable;
use App\Services\Pricing\Exceptions\PriceNotFound;
use App\Services\Pricing\ItemPricing;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Catalog search of the offer builder as its own component (bug fix 2026-10-20): typing only
 * re-renders the search results, never the builder (lines, header inputs, totals), so focus,
 * cursor, scroll and unsaved edits stay. Adding an item tells the builder (`offer-line-added`)
 * to refresh its lines; the search is cleared and keeps the focus.
 */
class ItemPicker extends Component
{
    #[Locked]
    public int $offerId;

    /** The group options are added to (null: a fixed line); set by the builder. */
    #[Reactive]
    public ?int $targetGroupId = null;

    public string $itemSearch = '';

    public string $itemQuantity = '1';

    public function mount(int $offerId, ?int $targetGroupId = null): void
    {
        $this->offerId = $offerId;
        $this->targetGroupId = $targetGroupId;
        $this->authorize('update', $this->offer);
    }

    #[Computed]
    public function offer(): Offer
    {
        return Offer::query()->with(['lines:id,offer_id,item_id,option_group_id', 'groups:id,offer_id'])->findOrFail($this->offerId);
    }

    /**
     * Sellable items by code, article, name or accounting codes, with this customer's price,
     * stock band and codes; inside an alternative group the linked analogues come first.
     *
     * @return list<array{item: Item, price: Money|null, band: StockBand|null, analogue: string|null}>
     */
    #[Computed]
    public function catalogResults(): array
    {
        $term = trim($this->itemSearch);
        $analogues = $this->analogueItemIds();

        if ($term === '' && $analogues === []) {
            return [];
        }

        $items = Item::query()
            ->sellable()
            ->with(['unit', 'stocks'])
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')
                ->orWhere('article', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%')
                ->orWhere('accountant_code', 'like', '%'.$term.'%')
                ->orWhere('class_code', 'like', '%'.$term.'%')))
            ->when($term === '', fn ($query) => $query->whereKey(array_keys($analogues)))
            ->orderBy('name')
            ->limit(12)
            ->get()
            ->sortBy(fn (Item $item): int => isset($analogues[$item->id]) ? 0 : 1)
            ->values();

        $pricing = app(ItemPricing::class);
        $quantity = preg_match('/^\d+([.,]\d+)?$/', trim($this->itemQuantity)) === 1 ? str_replace(',', '.', trim($this->itemQuantity)) : '1';
        $currency = $this->offer->currency;

        return $items->map(function (Item $item) use ($pricing, $quantity, $analogues, $currency): array {
            try {
                $price = $pricing->salePrice($item, $currency);
            } catch (ItemNotSellable|PriceNotFound|InvalidArgumentException) {
                $price = null;
            }

            return ['item' => $item, 'price' => $price, 'band' => StockBand::for($item, $quantity), 'analogue' => $analogues[$item->id] ?? null];
        })->all();
    }

    public function addItem(int $itemId, AddOfferLine $add): void
    {
        $offer = $this->offer;
        $this->authorize('update', $offer);
        $item = Item::query()->findOrFail($itemId);
        $group = $this->targetGroupId === null ? null : $offer->groups->firstWhere('id', $this->targetGroupId);

        try {
            $add->handle($offer, $item, $this->itemQuantity, auth()->user(), $group);
        } catch (OfferActionException $exception) {
            $this->addError('itemQuantity', $exception->getMessage());

            return;
        }

        $this->reset('itemSearch', 'itemQuantity');
        unset($this->offer, $this->catalogResults);
        $this->dispatch('offer-line-added')->to(Builder::class);
        $this->dispatch('item-picker-focus');
    }

    /**
     * Item ids linked as analogues (approved item_alternatives) of the target group's items.
     *
     * @return array<int, string> item id => relation label
     */
    protected function analogueItemIds(): array
    {
        if ($this->targetGroupId === null) {
            return [];
        }

        $itemIds = $this->offer->lines->where('option_group_id', $this->targetGroupId)->pluck('item_id')->all();

        if ($itemIds === []) {
            return [];
        }

        // Own links, plus two-way links made from the other item (Spec 01 section 17), in their order.
        $own = ItemAlternative::query()->whereIn('item_id', $itemIds)->whereNotIn('alternative_item_id', $itemIds)->where('status', 'approved')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (ItemAlternative $link): array => [$link->alternative_item_id => $link->relation->label()]);
        $reverse = ItemAlternative::query()->whereIn('alternative_item_id', $itemIds)->whereNotIn('item_id', $itemIds)->where('is_mutual', true)->where('status', 'approved')
            ->orderBy('sort_order')->orderBy('id')->get()
            ->mapWithKeys(fn (ItemAlternative $link): array => [$link->item_id => $link->relation->label()]);

        return $own->union($reverse)->all();
    }

    public function render(): View
    {
        return view('livewire.offers.item-picker');
    }
}
