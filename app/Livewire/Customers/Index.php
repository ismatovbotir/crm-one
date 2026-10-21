<?php

namespace App\Livewire\Customers;

use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Customers list (Spec 01 section 7): search by name, tax ID, email or phone; filter by owner,
 * "mine", sync status and archived. Only customers visible to the user are listed.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** '' = anyone, 'mine', or a user id. */
    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $sync = '';

    /** Spec 03 section 24.7: industry filter. */
    #[Url(except: '')]
    public string $industry = '';

    #[Url(except: false)]
    public bool $archived = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'owner', 'sync', 'archived', 'industry'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'owner', 'sync', 'archived', 'industry']);
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        $user = auth()->user();
        $search = trim($this->search);
        $ownerId = $this->owner === 'mine' ? $user->id : (ctype_digit($this->owner) ? (int) $this->owner : null);
        $sync = SyncStatus::tryFrom($this->sync);

        return Customer::query()
            ->visibleTo($user)
            ->with(['owner', 'primaryContact'])
            ->when($this->archived, fn ($query) => $query->onlyTrashed())
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $digits = Customer::normalizeTaxId($search);
                $phone = Customer::normalizePhone($search);

                $query->where(function ($q) use ($term, $digits, $phone): void {
                    $q->where('name', 'like', $term)
                        ->orWhere('legal_name', 'like', $term)
                        ->orWhere('email', 'like', mb_strtolower($term))
                        ->orWhereHas('contacts', fn ($c) => $c->where('email', 'like', mb_strtolower($term)));

                    if ($digits !== null) {
                        $q->orWhere('tax_id', 'like', '%'.$digits.'%');
                    }

                    if ($phone !== null && strlen($phone) >= 4) {
                        $q->orWhere('phone', 'like', '%'.ltrim($phone, '+').'%')
                            ->orWhereHas('contacts', fn ($c) => $c->where('phone', 'like', '%'.ltrim($phone, '+').'%'));
                    }
                });
            })
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->when($sync !== null, fn ($query) => $query->where('sync_status', $sync))
            ->when(ctype_digit($this->industry), fn ($query) => $query->where('industry_id', (int) $this->industry))
            ->orderBy('name')
            ->paginate(config('crm.per_page'));
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()
            ->whereIn('id', Customer::query()->visibleTo(auth()->user())->select('owner_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.customers.index', [
            'syncStatuses' => SyncStatus::options(),
            'industries' => ReferenceItem::query()->ofList('industry')->get(),
        ])->title(__('customers.title'));
    }
}
