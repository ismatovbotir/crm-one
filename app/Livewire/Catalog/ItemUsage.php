<?php

namespace App\Livewire\Catalog;

use App\Models\Item;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * "Usage" tab of the item page (read-only, paged): offers, orders and leads that contain the item,
 * each limited to the records the user may see. Loaded only when the tab is open.
 */
class ItemUsage extends Component
{
    use WithPagination;

    #[Locked]
    public int $itemId;

    public function mount(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->authorize('view', Item::withTrashed()->findOrFail($itemId));
    }

    /**
     * @return LengthAwarePaginator<int, Offer>|null
     */
    #[Computed]
    public function offers(): ?LengthAwarePaginator
    {
        return auth()->user()->can('viewAny', Offer::class)
            ? Offer::query()->visibleTo(auth()->user())->with('customer:id,name')->whereHas('lines', fn ($q) => $q->where('item_id', $this->itemId))->latest('id')->paginate(10, ['*'], 'offers-page')
            : null;
    }

    /**
     * @return LengthAwarePaginator<int, Order>|null
     */
    #[Computed]
    public function orders(): ?LengthAwarePaginator
    {
        return auth()->user()->can('viewAny', Order::class)
            ? Order::query()->visibleTo(auth()->user())->with('customer:id,name')->whereHas('lines', fn ($q) => $q->where('item_id', $this->itemId))->latest('id')->paginate(10, ['*'], 'orders-page')
            : null;
    }

    /**
     * @return LengthAwarePaginator<int, Lead>|null
     */
    #[Computed]
    public function leads(): ?LengthAwarePaginator
    {
        return auth()->user()->can('viewAny', Lead::class)
            ? Lead::query()->visibleTo(auth()->user())->with('customer:id,name')->whereHas('items', fn ($q) => $q->where('item_id', $this->itemId))->latest('id')->paginate(10, ['*'], 'leads-page')
            : null;
    }

    public function render(): View
    {
        return view('livewire.catalog.item-usage');
    }
}
