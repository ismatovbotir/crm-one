<?php

namespace App\Livewire\Stock;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\SerialStatus;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Order;
use App\Models\OrderLine;
use App\Services\Stock\SerialNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Serial search (Spec 06 21.10): exact or prefix match on the normalized serial, filter by status;
 * sales only see serials sold on their orders.
 */
#[Layout('layouts.app')]
class Serials extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $item = '';

    public function mount(): void
    {
        $this->authorize('viewAny', ItemSerial::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, ItemSerial>
     */
    #[Computed]
    public function serials(): LengthAwarePaginator
    {
        $user = auth()->user();
        $term = trim($this->search) === '' ? '' : (string) (app(SerialNormalizer::class)->normalize($this->search)['value'] ?? mb_strtoupper(trim($this->search)));

        return ItemSerial::query()->with(['item', 'warehouse', 'receiptLine.receipt'])
            ->when(PermissionMap::scopeFor($user, 'serials.view') !== DataScope::All, fn (Builder $query) => $query
                ->whereIn('sold_order_line_id', OrderLine::query()->select('id')->whereIn('order_id', Order::query()->visibleTo($user)->select('id'))))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('serial_normalized', $term)->orWhere('serial_normalized', 'like', $term.'%')))
            ->when(SerialStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status), fn (Builder $query) => $query->where('status', '!=', SerialStatus::WrittenOff->value))
            ->when(ctype_digit($this->item), fn (Builder $query) => $query->where('item_id', (int) $this->item))
            ->orderBy('serial_normalized')
            ->paginate(config('crm.per_page'));
    }

    #[On('serials-registered')]
    public function refreshList(): void
    {
        unset($this->serials);
    }

    public function render(): View
    {
        return view('livewire.stock.serials', [
            'statuses' => SerialStatus::options(),
            'items' => Item::query()->where('is_serialized', true)->orderBy('name')->limit(500)->get(['id', 'sku', 'name']),
        ])->title(__('stock.serials.title'));
    }
}
