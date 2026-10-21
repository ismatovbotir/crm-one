<?php

namespace App\Livewire\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Invoices list (Spec 06 10.2): proformas and registered tax documents; search by number, order,
 * customer or accountant code; filters type and status; void requests queue.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: false)]
    public bool $voidRequests = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Invoice::class);
    }

    public function updated(string $property): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Invoice::query()->visibleTo(auth()->user())
            ->with(['order', 'customer'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn (Builder $q) => $q->where('number', 'like', $term)->orWhere('esf_id', 'like', $term)
                    ->orWhereHas('order', fn (Builder $o) => $o->where('number', 'like', $term))
                    ->orWhereHas('customer', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $term))
                    ->orWhereHas('lines', fn (Builder $l) => $l->where('accountant_code', 'like', $term)->orWhere('class_code', 'like', $term)));
            })
            ->when(InvoiceType::tryFrom($this->type) !== null, fn (Builder $query) => $query->where('type', $this->type))
            ->when(InvoiceStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->voidRequests, fn (Builder $query) => $query->whereNotNull('void_requested_at')->where('status', InvoiceStatus::Issued))
            ->latest('issued_on')
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    public function render(): View
    {
        return view('livewire.invoices.index', [
            'types' => InvoiceType::options(),
            'statuses' => InvoiceStatus::options(),
        ])->title(__('accounting.invoices.title'));
    }
}
