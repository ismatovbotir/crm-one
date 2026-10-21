<?php

namespace App\Livewire\Stock;

use App\Actions\Stock\ManageGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ReceiptSource;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
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
 * Goods receipts list (Spec 06 21.10, 26.4): supplier receipts arrive from 1C; the "new" form
 * creates only the CRM kinds (customer return of a posted delivery note, opening balance).
 * Filters: status, origin (1C / CRM) and "needs review".
 */
#[Layout('layouts.app')]
class GoodsReceipts extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $origin = '';

    #[Url(except: false)]
    public bool $review = false;

    public bool $creating = false;

    /** @var array{source_type: string, warehouse_id: string, counterparty_name: string, supplier_doc_ref: string, return_of_delivery: string} */
    public array $form = ['source_type' => 'customer_return', 'warehouse_id' => '', 'counterparty_name' => '', 'supplier_doc_ref' => '', 'return_of_delivery' => ''];

    public function mount(): void
    {
        $this->authorize('viewAny', GoodsReceipt::class);
        $this->form['warehouse_id'] = (string) (Warehouse::query()->where('is_active', true)->orderBy('id')->value('id') ?? '');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'origin', 'review'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, GoodsReceipt>
     */
    #[Computed]
    public function receipts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return GoodsReceipt::query()->with(['warehouse', 'creator'])->withCount('lines')
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('number', 'like', '%'.$search.'%')->orWhere('supplier_doc_ref', 'like', '%'.$search.'%')->orWhere('external_number', 'like', '%'.$search.'%')->orWhere('counterparty_name', 'like', '%'.$search.'%')))
            ->when(GoodsReceiptStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(in_array($this->origin, ['onec', 'crm'], true), fn (Builder $query) => $query->where('origin', $this->origin))
            ->when($this->review, fn (Builder $query) => $query->where('needs_review', true))
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

    public function create(ManageGoodsReceipt $manage): void
    {
        $this->authorize('createKind', [GoodsReceipt::class, ReceiptSource::tryFrom($this->form['source_type']) ?? ReceiptSource::SupplierDelivery]);
        $this->validate([
            'form.source_type' => ['required', 'in:opening_balance,customer_return'],
            'form.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'form.counterparty_name' => ['nullable', 'string', 'max:255'],
            'form.supplier_doc_ref' => ['nullable', 'string', 'max:60'],
            'form.return_of_delivery' => ['required_if:form.source_type,customer_return', 'nullable', 'string', 'max:20'],
        ], [], [
            'form.source_type' => __('stock.fields.source'),
            'form.warehouse_id' => __('stock.fields.warehouse'),
            'form.return_of_delivery' => __('stock.fields.return_of_delivery'),
        ]);

        $deliveryId = $this->form['source_type'] === ReceiptSource::CustomerReturn->value
            ? Delivery::query()->where('number', strtoupper(trim($this->form['return_of_delivery'])))->value('id')
            : null;

        try {
            $receipt = $manage->create([
                'source_type' => $this->form['source_type'],
                'warehouse_id' => (int) $this->form['warehouse_id'],
                'counterparty_name' => $this->form['counterparty_name'] ?: null,
                'supplier_doc_ref' => $this->form['supplier_doc_ref'] ?: null,
                'return_of_delivery_id' => $deliveryId,
            ], auth()->user());
        } catch (StockActionException $exception) {
            $this->addError('form.return_of_delivery', $exception->getMessage());

            return;
        }

        $this->redirectRoute('goods-receipts.show', $receipt, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.stock.goods-receipts', [
            'statuses' => GoodsReceiptStatus::options(),
            'sources' => array_intersect_key(ReceiptSource::options(), array_flip(['customer_return', 'opening_balance'])),
        ])->title(__('stock.receipts.title'));
    }
}
