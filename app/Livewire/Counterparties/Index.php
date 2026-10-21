<?php

namespace App\Livewire\Counterparties;

use App\Actions\Counterparties\ManageCounterparty;
use App\Enums\CounterpartyStatus;
use App\Models\Counterparty;
use App\Models\Partner;
use App\Services\Organizations\OrganizationRoles;
use App\Services\Tenders\Exceptions\TenderActionException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Suppliers and carriers (Spec 03 T-1, section 9): search, role and status filters, quick create.
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

    public bool $creating = false;

    /** @var array{name: string, legal_name: string, tax_id: string, country: string, city: string, email: string, phone: string, is_supplier: bool, is_carrier: bool, preferred_locale: string} */
    /** Partner holding the entered tax ID (offered "add the role"), Spec 03 section 22. */
    #[Locked]
    public ?int $existingOrganizationId = null;

    public array $form = ['name' => '', 'legal_name' => '', 'tax_id' => '', 'country' => 'UZ', 'city' => '', 'email' => '', 'phone' => '', 'is_supplier' => false, 'is_carrier' => false, 'preferred_locale' => ''];

    public function mount(): void
    {
        $this->authorize('viewAny', Counterparty::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'status'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, Counterparty>
     */
    #[Computed]
    public function counterparties(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Counterparty::query()->visibleTo(auth()->user())
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', '%'.$search.'%')->orWhere('legal_name', 'like', '%'.$search.'%')->orWhere('tax_id', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
            ->when($this->type === 'supplier', fn (Builder $q) => $q->where('is_supplier', true))
            ->when($this->type === 'carrier', fn (Builder $q) => $q->where('is_carrier', true))
            ->when(CounterpartyStatus::tryFrom($this->status) !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->orderBy('name')
            ->paginate(config('crm.per_page'));
    }

    public function create(ManageCounterparty $manage): void
    {
        $this->authorize('create', Counterparty::class);
        $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.legal_name' => ['nullable', 'string', 'max:255'],
            'form.tax_id' => ['nullable', 'string', 'max:20'],
            'form.country' => ['nullable', 'string', 'size:2'],
            'form.city' => ['nullable', 'string', 'max:120'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.phone' => ['nullable', 'string', 'max:32'],
            'form.is_supplier' => ['boolean'],
            'form.is_carrier' => ['boolean'],
            'form.preferred_locale' => ['nullable', 'in:uz,ru,en'],
        ], [], [
            'form.name' => __('counterparties.fields.name'),
            'form.email' => __('counterparties.fields.email'),
            'form.country' => __('counterparties.fields.country'),
        ]);

        try {
            $counterparty = $manage->save(null, [
                ...array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value), $this->form),
                'country' => $this->form['country'] !== '' ? strtoupper($this->form['country']) : null,
            ], auth()->user());
        } catch (TenderActionException $exception) {
            $existing = app(OrganizationRoles::class)->findByTaxId($this->form['tax_id']);
            $this->existingOrganizationId = $existing !== null && ! $existing->trashed() ? $existing->id : null;
            $this->addError($this->existingOrganizationId !== null ? 'form.tax_id' : 'form.is_supplier', $exception->getMessage());

            return;
        }

        $this->redirectRoute('counterparties.show', $counterparty, navigate: true);
    }

    /**
     * Spec 03 section 22: the tax ID belongs to an existing organization (e.g. a customer); give it
     * the supplier/carrier role instead of creating a duplicate. Nothing on it is overwritten.
     */
    public function addRoleToExisting(OrganizationRoles $roles): void
    {
        $this->authorize('create', Counterparty::class);
        $organization = $this->existingOrganizationId !== null ? Partner::query()->find($this->existingOrganizationId) : null;

        if ($organization === null || (! $this->form['is_supplier'] && ! $this->form['is_carrier'])) {
            $this->addError('form.is_supplier', __('tenders.errors.counterparty_role_required'));

            return;
        }

        $profile = $roles->addSupplierRole($organization, ['is_supplier' => (bool) $this->form['is_supplier'], 'is_carrier' => (bool) $this->form['is_carrier']], auth()->user());
        session()->flash('status', __('organizations.role_added', ['name' => $organization->name]));
        $this->redirectRoute('counterparties.show', $profile, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.counterparties.index', ['statuses' => CounterpartyStatus::options()])->title(__('counterparties.title'));
    }
}
