<?php

namespace App\Livewire\Orders;

use App\Enums\AgreementStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Agreement;
use App\Models\Order;
use App\Models\User;
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
 * Orders list (Spec 06 10.2): search by number or customer; filters status, payment status, on
 * hold, owner and "agreement missing". Only orders visible to the user (Order::visibleTo).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $payment = '';

    /** '' = anyone, 'mine', or a user id. */
    #[Url(except: '')]
    public string $owner = '';

    /** '' | 'on_hold' | 'agreement_missing' */
    #[Url(except: '')]
    public string $queue = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Order::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'payment', 'owner', 'queue'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'payment', 'owner', 'queue']);
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Order>
     */
    #[Computed]
    public function orders(): LengthAwarePaginator
    {
        $user = auth()->user();
        $search = trim($this->search);
        $ownerId = $this->owner === 'mine' ? $user->id : (ctype_digit($this->owner) ? (int) $this->owner : null);

        return Order::query()
            ->visibleTo($user)
            ->with(['customer', 'owner', 'agreements' => fn ($query) => $query->where('status', '!=', AgreementStatus::Cancelled)])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn (Builder $q) => $q->where('number', 'like', $term)
                    ->orWhereHas('customer', fn (Builder $c) => $c->withTrashed()->where('name', 'like', $term)->orWhere('legal_name', 'like', $term)));
            })
            ->when(OrderStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(OrderPaymentStatus::tryFrom($this->payment) !== null, fn (Builder $query) => $query->where('payment_status', $this->payment))
            ->when($ownerId !== null, fn (Builder $query) => $query->where('owner_id', $ownerId))
            ->when($this->queue === 'on_hold', fn (Builder $query) => $query->where('on_hold', true))
            // Spec 06 section 29: shipped (fully or partly) but not fully paid, for follow-up.
            ->when($this->queue === 'shipped_unpaid', fn (Builder $query) => $query
                ->whereIn('status', [OrderStatus::PartiallyDelivered, OrderStatus::Delivered, OrderStatus::Invoiced])
                ->where('payment_status', '!=', OrderPaymentStatus::Paid))
            ->when($this->queue === 'poa_missing', fn (Builder $query) => $query
                ->where('poa_required', true)->whereNull('poa_received_at')->whereNull('poa_waived_at')
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Closed]))
            ->when($this->queue === 'agreement_missing', fn (Builder $query) => $query
                ->where('agreement_required', true)
                ->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Closed])
                ->whereNull('framework_agreement_id')
                ->whereDoesntHave('agreements', fn (Builder $a) => $a->whereIn('status', [AgreementStatus::Signed, AgreementStatus::Active])))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(config('crm.per_page'));
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()
            ->whereIn('id', Order::query()->visibleTo(auth()->user())->select('owner_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function agreementOf(Order $order): ?Agreement
    {
        return $order->agreements->first();
    }

    public function render(): View
    {
        return view('livewire.orders.index', [
            'statuses' => OrderStatus::options(),
            'paymentStatuses' => OrderPaymentStatus::options(),
        ])->title(__('orders.title'));
    }
}
