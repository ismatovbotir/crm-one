<?php

namespace App\Livewire\Customers;

use App\Enums\LegalType;
use App\Enums\Locale;
use App\Enums\SaleType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Partner;
use App\Models\User;
use App\Services\Customers\DuplicateCustomerFinder;
use App\Services\Organizations\OrganizationRoles;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer edit (Spec 01 section 7; creating goes through the Partners\Form wizard). Field groups are authorised separately:
 * general fields (update), owner (changeOwner), payment terms (commercial terms).
 * A live duplicate panel warns on similar name, email or phone; an existing tax ID blocks saving.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    private const GENERAL_FIELDS = [
        'name', 'legal_name', 'legal_type', 'tax_id', 'country', 'city', 'address', 'postal_code',
        'phone', 'email', 'website', 'currency', 'preferred_locale', 'notes',
    ];

    /**
     * Edit view tabs and the validation keys that live on each of them.
     *
     * @var array<string, list<string>>
     */
    private const TABS = [
        'company' => ['name', 'legal_name', 'legal_type', 'tax_id', 'industry_id', 'website'],
        'address' => ['country', 'city', 'address', 'postal_code', 'phone', 'email'],
        'commercial' => ['currency', 'preferred_locale', 'sale_type', 'payment_terms_days', 'sync_with_1c', 'owner_id', 'notes'],
    ];

    #[Locked]
    public ?int $customerId = null;

    /** Active tab of the edit view. */
    #[Url(as: 'tab')]
    public string $tab = 'company';

    public string $name = '';

    public string $legal_name = '';

    public string $legal_type = 'legal_entity';

    public string $tax_id = '';

    public string $country = 'UZ';

    public string $city = '';

    public string $address = '';

    public string $postal_code = '';

    public string $phone = '';

    public string $email = '';

    public string $website = '';

    /** Spec 03 section 24.7: managed industry list. */
    public string $industry_id = '';

    public string $currency = 'UZS';

    public string $payment_terms_days = '';

    public ?int $owner_id = null;

    public string $preferred_locale = '';

    /** Sale type (Spec 06 section 25): CRM-local, default b2b; orders snapshot it. */
    public string $sale_type = 'b2b';

    public string $notes = '';

    /** Spec 02 rev. 4: offered to 1C only when on (needs a tax ID); default off. */
    public bool $sync_with_1c = false;

    /** Existing customer that blocks saving because of the same tax ID. */
    #[Locked]
    public ?int $blockingCustomerId = null;

    public function mount(Customer $customer): void
    {
        $this->tab = $this->validTab($this->tab);
        $this->authorize('edit', $customer);

        $this->customerId = $customer->id;

        foreach (self::GENERAL_FIELDS as $field) {
            $value = $customer->getAttribute($field);
            $this->{$field} = $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
        }

        $this->payment_terms_days = (string) $customer->payment_terms_days;
        $this->industry_id = (string) ($customer->industry_id ?? '');
        $this->sale_type = $customer->sale_type->value;
        $this->owner_id = $customer->owner_id;
        $this->sync_with_1c = (bool) $customer->sync_with_1c;
    }

    /**
     * Country names in the UI language; the ISO 3166-1 code is derived from the chosen one.
     *
     * @return array<string, string> code => name
     */
    #[Computed]
    public function countries(): array
    {
        $options = Countries::options();
        $current = strtoupper($this->country);

        // A stored value outside the list stays selectable until the user picks another country.
        return $current === '' || isset($options[$current]) ? $options : [$current => Countries::name($current)] + $options;
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerId === null ? null : Customer::query()->findOrFail($this->customerId);
    }

    public function canEditGeneral(): bool
    {
        return auth()->user()->can('update', $this->customer);
    }

    public function canChangeOwner(): bool
    {
        return auth()->user()->can('changeOwner', $this->customer);
    }

    public function canEditCommercialTerms(): bool
    {
        return auth()->user()->can('updateCommercialTerms', $this->customer);
    }

    public function isTaxIdLocked(): bool
    {
        return (bool) $this->customer?->isLinkedToOneC();
    }

    /**
     * Live duplicate warnings (non-blocking, BR-6).
     *
     * @return SupportCollection<int, array{customer: Customer, reasons: list<string>}>
     */
    #[Computed]
    public function duplicates(): SupportCollection
    {
        return app(DuplicateCustomerFinder::class)
            ->warnings($this->name, $this->email, $this->phone, $this->customerId)
            ->filter(fn (array $row): bool => auth()->user()->can('view', $row['customer']))
            ->values();
    }

    /**
     * Live tax ID match (blocking, BR-5 / AC-1.2).
     */
    #[Computed]
    public function taxIdMatch(): ?Partner
    {
        return app(DuplicateCustomerFinder::class)->findByTaxId($this->tax_id, $this->customerId);
    }

    /**
     * Active internal users who may own customers: sales and managers, or sales department members (BR-13).
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        $salesDepartmentId = Department::query()->where('code', 'sales')->value('id');

        return User::query()
            ->internal()
            ->active()
            ->where(fn ($query) => $query
                ->whereIn('role', [UserRole::Sales->value, UserRole::Manager->value])
                ->orWhereHas('departments', fn ($q) => $q->whereKey($salesDepartmentId)))
            ->when($this->owner_id !== null, fn ($query) => $query->orWhere('id', $this->owner_id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $currencies = array_keys((array) config('crm.currencies'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'legal_type' => ['required', Rule::enum(LegalType::class)],
            'tax_id' => ['nullable', 'string', 'max:32'],
            'country' => ['required', Rule::in(Countries::CODES)],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:2000'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'industry_id' => ['nullable', Rule::exists('reference_items', 'id')->where('list', 'industry')],
            'currency' => ['required', Rule::in($currencies)],
            'preferred_locale' => ['nullable', Rule::enum(Locale::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sale_type' => ['required', Rule::enum(SaleType::class)],
            'owner_id' => ['required', 'integer', Rule::exists(User::class, 'id')->where('type', 'internal')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [];

        foreach ([...self::GENERAL_FIELDS, 'payment_terms_days', 'owner_id'] as $field) {
            $attributes[$field] = __('customers.fields.'.$field);
        }

        return $attributes;
    }

    public function updatedTab(): void
    {
        $this->tab = $this->validTab($this->tab);
    }

    protected function validTab(string $tab): string
    {
        return array_key_exists($tab, self::TABS) ? $tab : 'company';
    }

    /**
     * Tab definitions for the edit view; a tab holding an error carries a marker.
     *
     * @return array<string, array{label: string, marker: ?string}>
     */
    protected function tabs(): array
    {
        $tabs = [];

        foreach (self::TABS as $key => $fields) {
            $hasError = collect($fields)->contains(fn (string $field): bool => $this->getErrorBag()->has($field));
            $tabs[$key] = ['label' => __('customers.sections.'.$key), 'marker' => $hasError ? __('customers.tab_has_errors') : null];
        }

        return $tabs;
    }

    /** After a failed save the user lands on the first tab with an error (the current one if it has one). */
    protected function showTabWithError(): void
    {
        $withError = array_keys(array_filter(self::TABS, fn (array $fields): bool => collect($fields)->contains(fn (string $field): bool => $this->getErrorBag()->has($field))));

        if ($withError !== [] && ! in_array($this->tab, $withError, true)) {
            $this->tab = $withError[0];
        }
    }

    public function save(): void
    {
        try {
            $this->persist();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->showTabWithError();

            throw $exception;
        }
    }

    protected function persist(): void
    {
        $this->authorize('edit', $this->customer);

        $this->email = mb_strtolower(trim($this->email));
        $this->country = strtoupper(trim($this->country));
        $this->currency = strtoupper(trim($this->currency));
        $this->blockingCustomerId = null;

        $validated = $this->validate();
        $taxId = Customer::normalizeTaxId($validated['tax_id']);

        $this->validateTaxId($taxId);

        if ($this->sync_with_1c && $taxId === null) {
            $this->addError('sync_with_1c', __('customers.errors.sync_needs_tax_id'));
            $this->halt();
        }

        $attributes = $this->attributesFrom($validated, $taxId);

        $this->update($attributes);
    }

    protected function validateTaxId(?string $taxId): void
    {
        if ($this->isTaxIdLocked() && $taxId !== $this->customer->tax_id) {
            $this->addError('tax_id', __('customers.errors.tax_id_locked'));
            $this->halt();
        }

        if ($this->country === 'UZ' && $taxId !== null && preg_match('/^\d{9}$/', $taxId) !== 1) {
            $this->addError('tax_id', __('customers.errors.tax_id_uz'));
            $this->halt();
        }

        $existing = app(DuplicateCustomerFinder::class)->findByTaxId($taxId, $this->customerId);

        if ($existing !== null && ! $existing->is_customer && ! $existing->trashed()) {
            $this->blockingCustomerId = $existing->id;
            $this->addError('tax_id', __('organizations.tax_id_supplier', ['name' => $existing->name]));
            $this->halt();
        }

        if ($existing !== null) {
            $this->blockingCustomerId = $existing->id;
            $this->addError('tax_id', $existing->trashed()
                ? __('customers.errors.tax_id_archived', ['name' => $existing->name])
                : __('customers.errors.tax_id_taken', ['name' => $existing->name]));
            $this->halt();
        }
    }

    /**
     * Spec 03 section 22: the entered tax ID belongs to a supplier/carrier organization; give it the
     * customer role (offered to 1C as a new customer) and continue on its customer card.
     */
    public function addCustomerRole(OrganizationRoles $roles): void
    {
        $this->authorize('create', Customer::class);
        // Re-derived from the entered tax ID (never from a client-side id).
        $organization = app(OrganizationRoles::class)->findByTaxId($this->tax_id, $this->customerId);
        abort_if($organization === null || $organization->is_customer || $organization->trashed(), 404);
        $customer = $roles->addCustomerRole($organization, auth()->user());
        session()->flash('status', __('organizations.role_added', ['name' => $customer->name]));
        $this->redirectRoute('customers.edit', $customer, navigate: true);
    }

    /**
     * Stops the action after errors were added (Livewire keeps the error bag).
     */
    protected function halt(): never
    {
        throw ValidationException::withMessages($this->getErrorBag()->toArray());
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $validated, ?string $taxId): array
    {
        $attributes = [];

        foreach (self::GENERAL_FIELDS as $field) {
            $value = $validated[$field] ?? null;
            $attributes[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }

        $attributes['tax_id'] = $taxId;
        $attributes['industry_id'] = $this->industry_id !== '' ? (int) $this->industry_id : null;

        $attributes['payment_terms_days'] = $validated['payment_terms_days'] === '' || $validated['payment_terms_days'] === null ? null : (int) $validated['payment_terms_days'];
        $attributes['owner_id'] = (int) $validated['owner_id'];
        $attributes['sale_type'] = $validated['sale_type'];
        $attributes['sync_with_1c'] = $this->sync_with_1c && $taxId !== null;

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function update(array $attributes): void
    {
        $customer = $this->customer;
        $customer->fill($attributes);
        $dirty = array_keys($customer->getDirty());

        if (array_intersect($dirty, [...self::GENERAL_FIELDS, 'sale_type']) !== []) {
            $this->authorize('update', $customer);
        }

        if (in_array('owner_id', $dirty, true)) {
            $this->authorize('changeOwner', $customer);
        }

        if (in_array('payment_terms_days', $dirty, true)) {
            $this->authorize('updateCommercialTerms', $customer);
        }

        $customer->save();

        session()->flash('status', __('customers.updated', ['name' => $customer->name]));

        $this->redirectRoute('customers.show', $customer, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.customers.form', [
            'legalTypes' => LegalType::options(),
            'currencies' => array_keys((array) config('crm.currencies')),
            'locales' => Locale::available(),
            'tabs' => $this->tabs(),
        ])->title(__('customers.edit_title', ['name' => $this->customer->name]));
    }
}
