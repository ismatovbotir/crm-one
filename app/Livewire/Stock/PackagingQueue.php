<?php

namespace App\Livewire\Stock;

use App\Actions\Orders\StartPackaging;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderFlow;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Warehouse packaging queue (Spec 06 section 25): orders waiting for packaging (confirmed, with the
 * payment and agreement gate shown), being packed, packed and waiting for delivery, and partly
 * delivered with a rest to pack. Each row links to the order and its open packing list.
 */
#[Layout('layouts.app')]
class PackagingQueue extends Component
{
    use WithPagination;

    /** waiting | packing | packed | all */
    #[Url(except: 'all')]
    public string $stage = 'all';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewPackaging', Order::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['stage', 'search'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return array<string, list<OrderStatus>>
     */
    protected function stages(): array
    {
        return [
            'waiting' => [OrderStatus::Confirmed, OrderStatus::PartiallyDelivered],
            'packing' => [OrderStatus::Packing],
            'packed' => [OrderStatus::Packed],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $statuses = $this->stages()[$this->stage] ?? array_merge(...array_values($this->stages()));
        $term = trim($this->search);

        return Order::query()
            ->with(['customer:id,name', 'deliveries'])
            ->whereIn('status', $statuses)
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('number', 'like', '%'.$term.'%')->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$term.'%'))))
            ->orderByRaw('promised_delivery_on is null')
            ->orderBy('promised_delivery_on')
            ->orderBy('id')
            ->paginate(config('crm.per_page'));
    }

    public function render(StartPackaging $gate, OrderFlow $flow): View
    {
        return view('livewire.stock.packaging-queue', [
            'gate' => $gate,
            'flow' => $flow,
            'stageOptions' => ['all', 'waiting', 'packing', 'packed'],
        ])->title(__('stock.packaging.title'));
    }
}
