<?php

namespace App\Livewire\Stock;

use App\Enums\StockDocumentStatus;
use App\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Delivery notes list (Spec 06 21.10): drafts to post first (oldest first), search by number,
 * order or customer.
 */
#[Layout('layouts.app')]
class Deliveries extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'draft')]
    public string $status = 'draft';

    public function mount(): void
    {
        $this->authorize('viewAny', Delivery::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Delivery>
     */
    #[Computed]
    public function deliveries(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Delivery::query()->visibleTo(auth()->user())->with(['order.customer', 'warehouse'])->withCount('lines')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('number', 'like', '%'.$search.'%')
                ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', '%'.$search.'%')->orWhereHas('customer', fn (Builder $c) => $c->withTrashed()->where('name', 'like', '%'.$search.'%')))))
            ->when(StockDocumentStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->status === 'draft', fn (Builder $query) => $query->oldest('id'), fn (Builder $query) => $query->latest('id'))
            ->paginate(config('crm.per_page'));
    }

    public function render(): View
    {
        return view('livewire.stock.deliveries', ['statuses' => StockDocumentStatus::options()])->title(__('stock.deliveries.title'));
    }
}
