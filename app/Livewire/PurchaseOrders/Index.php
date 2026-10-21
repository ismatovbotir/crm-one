<?php

namespace App\Livewire\PurchaseOrders;

use App\Actions\Tenders\ManagePurchaseOrder;
use App\Enums\Incoterm;
use App\Enums\PurchaseOrderStatus;
use App\Models\Counterparty;
use App\Models\PurchaseOrder;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Purchase orders to suppliers (purchase-tender skill 6, Spec 08 12.1): created from accepted
 * purchase awards; manual POs come with Spec 08.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $creating = false;

    /** @var array{supplier_id: string, currency: string, incoterm: string, incoterm_place: string} */
    public array $form = ['supplier_id' => '', 'currency' => 'USD', 'incoterm' => 'FCA', 'incoterm_place' => ''];

    public function mount(): void
    {
        $this->authorize('viewAny', PurchaseOrder::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return PurchaseOrder::query()->visibleTo(auth()->user())->with(['supplier', 'owner'])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('number', 'like', '%'.$search.'%')->orWhere('onec_number', 'like', '%'.$search.'%')->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', '%'.$search.'%'))))
            ->when(PurchaseOrderStatus::tryFrom($this->status) !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    public function create(ManagePurchaseOrder $manage): void
    {
        $this->authorize('create', PurchaseOrder::class);
        $this->validate([
            'form.supplier_id' => ['required', 'integer', 'exists:counterparties,id'],
            'form.currency' => ['required', Rule::in(config('tenders.currencies'))],
            'form.incoterm' => ['nullable', Rule::enum(Incoterm::class)],
            'form.incoterm_place' => ['nullable', 'string', 'max:255'],
        ], [], ['form.supplier_id' => __('purchase_orders.fields.supplier')]);

        try {
            $po = $manage->create([
                'supplier_id' => (int) $this->form['supplier_id'],
                'currency' => $this->form['currency'],
                'incoterm' => $this->form['incoterm'] ?: null,
                'incoterm_place' => trim($this->form['incoterm_place']) ?: null,
            ], auth()->user());
        } catch (TenderActionException $exception) {
            $this->addError('form.supplier_id', $exception->getMessage());

            return;
        }

        $this->redirectRoute('purchase-orders.show', $po, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.purchase-orders.index', [
            'statuses' => PurchaseOrderStatus::options(),
            'suppliers' => $this->creating ? Counterparty::query()->where('is_supplier', true)->whereNotIn('status', ['suspended', 'blacklisted'])->orderBy('name')->get(['id', 'name']) : collect(),
            'incoterms' => Incoterm::options(),
        ])->title(__('purchase_orders.title'));
    }
}
