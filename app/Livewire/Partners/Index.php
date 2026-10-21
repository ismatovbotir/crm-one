<?php

namespace App\Livewire\Partners;

use App\Authorization\PermissionMap;
use App\Enums\SyncStatus;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Partners (Spec 03 section 24.2): every organization with role badges, filters by role, status,
 * owner and 1C sync, each row linking to the right card (customer card, else supplier card).
 * `partners.view` sees all; others only the roles they may see (sales: customers; purchase and
 * logistics: suppliers and carriers).
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $sync = '';

    /** Spec 03 section 24.7: industry filter. */
    #[Url(except: '')]
    public string $industry = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Partner::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'role', 'status', 'owner', 'sync', 'industry'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Roles this user may see in the list.
     *
     * @return list<string>
     */
    #[Computed]
    public function visibleRoles(): array
    {
        $user = auth()->user();

        if ($user->can('viewAll', Partner::class)) {
            return ['customer', 'supplier', 'carrier'];
        }

        return array_values(array_filter([
            $user->can('viewAny', Customer::class) ? 'customer' : null,
            $user->can('viewAny', Counterparty::class) ? 'supplier' : null,
            $user->can('viewAny', Counterparty::class) ? 'carrier' : null,
        ]));
    }

    /**
     * @return LengthAwarePaginator<int, Partner>
     */
    #[Computed]
    public function partners(): LengthAwarePaginator
    {
        $user = auth()->user();
        $roles = $this->visibleRoles;
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Partner::query()->with('owner:id,name')
            ->when(! $user->can('viewAll', Partner::class), function (Builder $query) use ($roles, $user): void {
                $query->where(function (Builder $where) use ($roles, $user): void {
                    if (in_array('customer', $roles, true)) {
                        $where->orWhere(fn (Builder $customer) => $customer->where('is_customer', true)->where(fn (Builder $scope) => PermissionMap::applyScope($scope, $user, 'customers.view')));
                    }

                    if (in_array('supplier', $roles, true)) {
                        $where->orWhere('is_supplier', true)->orWhere('is_carrier', true);
                    }

                    if ($roles === []) {
                        $where->whereRaw('1 = 0');
                    }
                });
            })
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('legal_name', 'like', $like)->orWhere('tax_id', 'like', $like)))
            ->when(in_array($this->role, ['customer', 'supplier', 'carrier'], true), fn (Builder $q) => $q->where('is_'.$this->role, true))
            ->when($this->status === 'archived', fn (Builder $q) => $q->onlyTrashed(), fn (Builder $q) => $this->status === 'all' ? $q->withTrashed() : $q)
            ->when($this->owner === 'mine', fn (Builder $q) => $q->where('owner_id', $user->id))
            ->when(SyncStatus::tryFrom($this->sync) !== null, fn (Builder $q) => $q->where('sync_status', $this->sync))
            ->when(ctype_digit($this->industry), fn (Builder $q) => $q->where('industry_id', (int) $this->industry))
            ->orderBy('name')
            ->paginate(config('crm.per_page'));
    }

    /** The card a row opens: the customer card for customers, else the supplier card. */
    public static function cardUrl(Partner $partner, User $user): ?string
    {
        if ($partner->is_customer && $user->can('view', Customer::withTrashed()->find($partner->id) ?? $partner)) {
            return route('customers.show', $partner->id);
        }

        $profile = $partner->isSupplierOrCarrier() ? Counterparty::withTrashed()->where('partner_id', $partner->id)->first() : null;

        return $profile !== null && $user->can('view', $profile) ? route('counterparties.show', $profile) : null;
    }

    public function render(): View
    {
        return view('livewire.partners.index', [
            'syncStatuses' => SyncStatus::options(),
            'industries' => ReferenceItem::query()->ofList('industry')->get(),
            'canCreate' => auth()->user()->can('create', Partner::class),
        ])->title(__('partners.title'));
    }
}
