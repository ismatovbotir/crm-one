<?php

namespace App\Livewire\Catalog;

use App\Enums\ItemAlternativeRelation;
use App\Enums\StockBand;
use App\Models\Item;
use App\Models\ItemAlternative;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Analogues" tab of the item page (Spec 01 section 17, Spec 05 7.10): CRM-local analogue links
 * (`item_alternatives`, never overwritten by 1C) with relation analogue or upgrade, note, order and
 * a two-way option; stock band and sale price of each analogue (staff only); "Used as analogue of".
 * The offer builder suggests these items. Managed with `manageAnalogues` (catalog.manage_alternatives
 * or catalog.edit_local).
 */
class ItemAnalogues extends Component
{
    #[Locked]
    public int $itemId;

    public string $search = '';

    public ?int $candidateId = null;

    public string $relation = 'analogue';

    public string $note = '';

    public string $sortOrder = '0';

    public bool $twoWay = false;

    public function mount(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->authorize('view', $this->item);
    }

    #[Computed]
    public function item(): Item
    {
        return Item::withTrashed()->findOrFail($this->itemId);
    }

    /**
     * Analogues of this item: own links plus two-way links made from the other side.
     *
     * @return Collection<int, array{link: ItemAlternative, item: Item, reverse: bool}>
     */
    #[Computed]
    public function analogues(): Collection
    {
        $own = ItemAlternative::query()->with('alternative.stocks', 'alternative.unit')->where('item_id', $this->itemId)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ItemAlternative $link): array => ['link' => $link, 'item' => $link->alternative, 'reverse' => false]);
        $mutual = ItemAlternative::query()->with('item.stocks', 'item.unit')->where('alternative_item_id', $this->itemId)->where('is_mutual', true)->orderBy('sort_order')->get()
            ->map(fn (ItemAlternative $link): array => ['link' => $link, 'item' => $link->item, 'reverse' => true]);

        return $own->concat($mutual)->filter(fn (array $row): bool => $row['item'] !== null)->values();
    }

    /**
     * Items that list this one as their (one-way) analogue.
     *
     * @return Collection<int, ItemAlternative>
     */
    #[Computed]
    public function usedBy(): Collection
    {
        return ItemAlternative::query()->with('item')->where('alternative_item_id', $this->itemId)->where('is_mutual', false)->get();
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function candidates(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $taken = $this->analogues->map(fn (array $row): int => $row['item']->id)->push($this->itemId)->all();
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Item::query()->where('is_active', true)->whereNotIn('id', $taken)
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('article', 'like', $like))
            ->orderBy('name')->limit(15)->get(['id', 'name', 'sku']);
    }

    public function add(): void
    {
        $this->authorize('manageAnalogues');
        $this->validate([
            'candidateId' => ['required', 'integer', Rule::exists('items', 'id'), Rule::notIn([$this->itemId])],
            'relation' => ['required', Rule::in([ItemAlternativeRelation::Analogue->value, ItemAlternativeRelation::Upgrade->value])],
            'note' => ['nullable', 'string', 'max:255'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:1000'],
        ], [], ['candidateId' => __('catalog.analogues.item'), 'relation' => __('catalog.analogues.relation')]);

        $exists = ItemAlternative::query()->where(fn ($q) => $q->where('item_id', $this->itemId)->where('alternative_item_id', $this->candidateId))
            ->orWhere(fn ($q) => $q->where('item_id', $this->candidateId)->where('alternative_item_id', $this->itemId)->where('is_mutual', true))->exists();

        if ($exists) {
            $this->addError('candidateId', __('catalog.analogues.exists'));

            return;
        }

        ItemAlternative::query()->create([
            'item_id' => $this->itemId, 'alternative_item_id' => $this->candidateId, 'relation' => $this->relation, 'is_mutual' => $this->twoWay,
            'note' => filled($this->note) ? trim($this->note) : null, 'source' => 'crm', 'status' => 'approved', 'created_by' => auth()->id(), 'approved_by' => auth()->id(),
        ])->forceFill(['sort_order' => (int) $this->sortOrder])->save();

        $this->reset('search', 'candidateId', 'note', 'twoWay');
        $this->sortOrder = '0';
        unset($this->analogues, $this->candidates);
        session()->flash('status', __('catalog.analogues.added'));
    }

    public function remove(int $linkId): void
    {
        $this->authorize('manageAnalogues');
        $link = ItemAlternative::query()->where(fn ($q) => $q->where('item_id', $this->itemId)->orWhere(fn ($w) => $w->where('alternative_item_id', $this->itemId)->where('is_mutual', true)))->findOrFail($linkId);
        $link->delete();
        unset($this->analogues);
        session()->flash('status', __('catalog.analogues.removed'));
    }

    public function stockBand(Item $item): ?StockBand
    {
        return StockBand::for($item, '1');
    }

    public function render(): View
    {
        return view('livewire.catalog.item-analogues', ['relations' => [ItemAlternativeRelation::Analogue, ItemAlternativeRelation::Upgrade]]);
    }
}
