<?php

namespace App\Livewire\Stock;

use App\Actions\StockOff\ManageStockOffAct;
use App\Enums\StockOffReason;
use App\Enums\StockOffStatus;
use App\Models\StockOffAct;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockOffException;
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
 * Stock-off acts list and "new act" form (Spec 06 27.6): filters by status, reason and supplier
 * claim; acts opened from receipt damage show their receipt.
 */
#[Layout('layouts.app')]
class StockOffActs extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $reason = '';

    #[Url(except: '')]
    public string $claim = '';

    public bool $creating = false;

    /** @var array{warehouse_id: string, reason: string, note: string} */
    public array $form = ['warehouse_id' => '', 'reason' => 'damaged_in_storage', 'note' => ''];

    public function mount(): void
    {
        $this->authorize('viewAny', StockOffAct::class);
        $this->form['warehouse_id'] = (string) (Warehouse::query()->where('is_active', true)->orderBy('id')->value('id') ?? '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'reason', 'claim'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, StockOffAct>
     */
    #[Computed]
    public function acts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return StockOffAct::query()->with(['warehouse', 'goodsReceipt'])->withCount('lines')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('number', 'like', '%'.$search.'%')->orWhere('claim_ref', 'like', '%'.$search.'%')))
            ->when(StockOffStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(StockOffReason::tryFrom($this->reason) !== null, fn (Builder $query) => $query->where('reason', $this->reason))
            ->when(in_array($this->claim, ['open', 'settled', 'rejected'], true), fn (Builder $query) => $query->where('claim_status', $this->claim))
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

    public function create(ManageStockOffAct $manage): void
    {
        $this->authorize('create', StockOffAct::class);
        $this->validate([
            'form.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'form.reason' => ['required', 'in:'.implode(',', array_keys(StockOffReason::options()))],
            'form.note' => [$this->form['reason'] === StockOffReason::Other->value ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [], [
            'form.warehouse_id' => __('stock.fields.warehouse'),
            'form.reason' => __('stock_off.fields.reason'),
            'form.note' => __('stock_off.fields.note'),
        ]);

        try {
            $act = $manage->create(['warehouse_id' => (int) $this->form['warehouse_id'], 'reason' => $this->form['reason'], 'note' => $this->form['note']], auth()->user());
        } catch (StockOffException $exception) {
            $this->addError('form.reason', $exception->getMessage());

            return;
        }

        $this->redirectRoute('stock-off.show', $act, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.stock.stock-off-acts', [
            'statuses' => StockOffStatus::options(),
            'reasons' => StockOffReason::options(),
        ])->title(__('stock_off.title'));
    }
}
