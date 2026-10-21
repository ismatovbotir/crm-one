<?php

namespace App\Livewire\Offers;

use App\Enums\OfferStatus;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Offers list (Spec 05 10.1): search by number or customer, filter by status, owner, customer and
 * creation date. Only offers visible to the user are listed (OfferPolicy / Offer::visibleTo).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    /** '' = anyone, 'mine', or a user id. */
    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $customer = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Offer::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'owner', 'customer', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'owner', 'customer', 'from', 'to']);
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Offer>
     */
    #[Computed]
    public function offers(): LengthAwarePaginator
    {
        $user = auth()->user();
        $search = trim($this->search);
        $status = OfferStatus::tryFrom($this->status);
        $ownerId = $this->owner === 'mine' ? $user->id : (ctype_digit($this->owner) ? (int) $this->owner : null);
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->from) === 1 ? $this->from : null;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->to) === 1 ? $this->to : null;

        return Offer::query()
            ->visibleTo($user)
            ->with(['customer', 'owner'])->withCount('groups')
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn ($q) => $q->where('number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->withTrashed()->where('name', 'like', $term)->orWhere('legal_name', 'like', $term)));
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->when(ctype_digit($this->customer), fn ($query) => $query->where('customer_id', (int) $this->customer))
            // Local (Tashkent) days converted to UTC ranges: sargable, no 5-hour shift (DB review 5.4).
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', CarbonImmutable::parse($from, (string) config('offers.timezone'))->startOfDay()->utc()))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<', CarbonImmutable::parse($to, (string) config('offers.timezone'))->addDay()->startOfDay()->utc()))
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
            ->whereIn('id', Offer::query()->visibleTo(auth()->user())->select('owner_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::withTrashed()
            ->whereIn('id', Offer::query()->visibleTo(auth()->user())->select('customer_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.offers.index', [
            'statuses' => OfferStatus::options(),
        ])->title(__('offers.title'));
    }
}
