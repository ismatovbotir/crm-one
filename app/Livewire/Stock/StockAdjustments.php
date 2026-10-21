<?php

namespace App\Livewire\Stock;

use App\Actions\StockAdjustment\ManageStockAdjustment;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockAdjustmentType;
use App\Models\Delivery;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockAdjustmentException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock adjustments list and "new adjustment" form (Spec 06 21.4): filters by status and type;
 * a serial correction is opened against a posted delivery note (found by its number).
 */
#[Layout('layouts.app')]
class StockAdjustments extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    public bool $creating = false;

    /** @var array{type: string, warehouse_id: string, delivery_number: string, reason: string} */
    public array $form = ['type' => 'found', 'warehouse_id' => '', 'delivery_number' => '', 'reason' => ''];

    public function mount(): void
    {
        $this->authorize('viewAny', StockAdjustment::class);
        $this->form['warehouse_id'] = (string) (Warehouse::query()->where('is_active', true)->orderBy('id')->value('id') ?? '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'type'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, StockAdjustment>
     */
    #[Computed]
    public function adjustments(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return StockAdjustment::query()->with(['warehouse', 'delivery'])->withCount('lines')
            ->when($search !== '', fn (Builder $query) => $query->where('number', 'like', '%'.$search.'%'))
            ->when(StockAdjustmentStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(StockAdjustmentType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    /**
     * @return Collection<int, Warehouse>
     */
    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    public function create(ManageStockAdjustment $manage): void
    {
        $this->authorize('create', StockAdjustment::class);
        $correction = $this->form['type'] === StockAdjustmentType::SerialCorrection->value;
        $this->validate([
            'form.type' => ['required', 'in:'.implode(',', array_keys(StockAdjustmentType::options()))],
            'form.warehouse_id' => [$correction ? 'nullable' : 'required', 'integer', 'exists:warehouses,id'],
            'form.delivery_number' => [$correction ? 'required' : 'nullable', 'string', 'max:20'],
            'form.reason' => ['required', 'string', 'max:500'],
        ], [], [
            'form.type' => __('stock_adjustments.fields.type'),
            'form.warehouse_id' => __('stock.fields.warehouse'),
            'form.delivery_number' => __('stock_adjustments.fields.delivery'),
            'form.reason' => __('stock_adjustments.fields.reason'),
        ]);

        $delivery = $correction ? Delivery::query()->where('number', trim($this->form['delivery_number']))->first() : null;

        if ($correction && $delivery === null) {
            $this->addError('form.delivery_number', __('stock_adjustments.errors.delivery_required'));

            return;
        }

        try {
            $adjustment = $manage->create([
                'type' => $this->form['type'],
                'warehouse_id' => $this->form['warehouse_id'] !== '' ? (int) $this->form['warehouse_id'] : null,
                'delivery_id' => $delivery?->id,
                'reason' => $this->form['reason'],
            ], auth()->user());
        } catch (StockAdjustmentException $exception) {
            $this->addError('form.type', $exception->getMessage());

            return;
        }

        $this->redirectRoute('stock-adjustments.show', $adjustment, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.stock.stock-adjustments', [
            'statuses' => StockAdjustmentStatus::options(),
            'types' => StockAdjustmentType::options(),
        ])->title(__('stock_adjustments.title'));
    }
}
