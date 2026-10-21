<?php

namespace App\Livewire\Leads;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Customer;
use App\Models\Lead;
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
 * Leads list (Spec 04 section 8): search by number, company, tax ID, contact e-mail or phone;
 * filter by status (default open), source, owner / mine and stale. Only visible leads are listed.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    /** 'open' (default), 'all' or a LeadStatus value. */
    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: '')]
    public string $source = '';

    /** '' = anyone, 'mine', 'none' (unassigned), or a user id. */
    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: false)]
    public bool $stale = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Lead::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'source', 'owner', 'stale'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'source', 'owner', 'stale']);
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Lead>
     */
    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        $user = auth()->user();
        $search = trim($this->search);
        $status = LeadStatus::tryFrom($this->status);
        $source = LeadSource::tryFrom($this->source);
        $ownerId = $this->owner === 'mine' ? $user->id : (ctype_digit($this->owner) ? (int) $this->owner : null);

        return Lead::query()
            ->visibleTo($user)
            ->with(['owner:id,name', 'customer:id,name', 'contact'])
            ->when($this->status === 'open', fn ($query) => $query->whereIn('status', LeadStatus::openValues()))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($source !== null, fn ($query) => $query->where('source', $source))
            ->when($ownerId !== null, fn ($query) => $query->where('owner_id', $ownerId))
            ->when($this->owner === 'none', fn ($query) => $query->whereNull('owner_id'))
            ->when($this->stale, fn ($query) => $query->stale())
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $digits = Customer::normalizeTaxId($search);
                $phoneDigits = preg_replace('/[^\d+]/', '', $search) ?? '';

                $query->where(function ($q) use ($term, $digits, $phoneDigits): void {
                    $q->where('number', 'like', $term)
                        ->orWhere('company_name', 'like', $term)
                        ->orWhere('contact_name', 'like', $term)
                        ->orWhere('contact_email', 'like', mb_strtolower($term))
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $term));

                    if (strlen($phoneDigits) >= 4) {
                        $q->orWhere('contact_phone', 'like', '%'.$phoneDigits.'%');
                    }

                    if ($digits !== null) {
                        $q->orWhere('tax_id', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate(config('crm.per_page'));
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        return User::query()
            ->whereIn('id', Lead::query()->visibleTo(auth()->user())->select('owner_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function render(): View
    {
        return view('livewire.leads.index', [
            'statuses' => LeadStatus::options(),
            'sources' => LeadSource::options(),
        ])->title(__('leads.title'));
    }
}
