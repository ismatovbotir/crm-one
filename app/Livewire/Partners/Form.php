<?php

namespace App\Livewire\Partners;

use App\Actions\Leads\LeadWorkflow;
use App\Actions\Partners\ChangePartnerRoles;
use App\Actions\Partners\CreatePartner;
use App\Authorization\PermissionMap;
use App\Enums\LegalType;
use App\Enums\Locale;
use App\Enums\SaleType;
use App\Models\Customer;
use App\Models\GeoPlace;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\ReferenceItem;
use App\Models\TelegramChat;
use App\Models\User;
use App\Policies\PartnerPolicy;
use App\Services\Customers\DuplicateCustomerFinder;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Partners\Exceptions\PartnerException;
use App\Support\Countries;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "New partner" (Spec 03 sections 24.3 and 24.7, field decisions 2026-10-19):
 *  type checkboxes (only the roles the user may create), legal type (4 values) with the rules it
 *  drives (legal name and tax ID required except for a private person, sale type default),
 *  country dropdown (Uzbekistan first, neighbours, frequent countries, rest A-Z) with UZ-only
 *  region/city dropdowns and free-text city elsewhere, one address + postal code, managed lists
 *  (industry, currency, contact position, supplier category), owner limited by role, document
 *  language without an empty option. An existing tax ID is refused; the roles can be added to it.
 *
 * Create is a five-step wizard: "Next" validates only the fields of the current step, earlier steps
 * can be revisited, and "Create" validates everything and jumps back to the first step with an error.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    /** Step keys in order; the labels are `partners.wizard.steps.*`. */
    public const STEPS = ['roles', 'identity', 'address', 'terms', 'contact'];

    /**
     * Validation keys that belong to each step.
     *
     * @var array<string, list<string>>
     */
    private const STEP_KEYS = [
        'roles' => ['roles'],
        'identity' => ['form.legal_type', 'form.name', 'form.legal_name', 'form.tax_id', 'form.industry_id', 'form.website'],
        'address' => ['form.country', 'form.region_id', 'form.city_id', 'form.city', 'form.address', 'form.postal_code', 'form.phone', 'form.email'],
        'terms' => ['form.preferred_locale', 'form.notes', 'form.currency', 'form.sale_type', 'form.payment_terms_days', 'form.owner_id', 'form.supplier_category_id', 'form.fleet_size', 'syncWith1c'],
        'contact' => ['form.contact_first_name', 'form.contact_last_name', 'form.contact_position', 'form.contact_email', 'form.contact_phone'],
    ];

    /** Current step (1-based) and the furthest one reached; only changed by the step actions. */
    #[Locked]
    public int $step = 1;

    #[Locked]
    public int $furthest = 1;

    /** @var list<string> */
    public array $roles = [];

    /** @var array<string, string> */
    public array $form = [
        'name' => '', 'legal_name' => '', 'legal_type' => 'legal_entity', 'tax_id' => '', 'industry_id' => '', 'website' => '',
        'country' => 'UZ', 'region_id' => '', 'city_id' => '', 'city' => '', 'address' => '', 'postal_code' => '', 'phone' => '', 'email' => '',
        'preferred_locale' => 'uz', 'notes' => '', 'currency' => 'UZS', 'sale_type' => 'b2b', 'payment_terms_days' => '0', 'owner_id' => '',
        'supplier_category_id' => '', 'fleet_size' => '',
        'contact_first_name' => '', 'contact_last_name' => '', 'contact_position' => '', 'contact_email' => '', 'contact_phone' => '',
    ];

    public bool $hasTir = false;

    public bool $hasAdr = false;

    /** Spec 02 rev. 4: customer role only; needs a tax ID; default off. */
    public bool $syncWith1c = false;

    public string $countrySearch = '';

    public string $ownerSearch = '';

    /** The lead this company is created from ("Create company" on a lead or Telegram group). */
    #[Locked]
    public ?int $fromLeadId = null;

    public function mount(): void
    {
        $this->authorize('create', Partner::class);
        $allowed = $this->allowedRoles;
        $wanted = request()->routeIs('customers.create') ? 'customer' : (string) request()->query('role', '');
        $this->roles = in_array($wanted, $allowed, true) ? [$wanted] : (count($allowed) === 1 ? $allowed : []);
        $this->form['country'] = Countries::isValid((string) config('crm.default_country', 'UZ')) ? strtoupper((string) config('crm.default_country', 'UZ')) : 'UZ';
        $currencies = $this->currencies;
        $this->form['currency'] = in_array((string) config('crm.base_currency', 'UZS'), $currencies, true) ? (string) config('crm.base_currency', 'UZS') : ($currencies[0] ?? 'UZS');
        $this->form['owner_id'] = (string) auth()->id();

        if (in_array('customer', $this->roles, true)) {
            $this->prefillFromLead(request()->integer('lead') ?: null);
        }
    }

    protected function prefillFromLead(?int $leadId): void
    {
        $lead = $leadId !== null ? Lead::query()->find($leadId) : null;

        if ($lead === null || $lead->customer_id !== null || ! auth()->user()->can('update', $lead)) {
            return;
        }

        $this->fromLeadId = $lead->id;
        [$first, $last] = array_pad(explode(' ', trim((string) $lead->contact_name), 2), 2, '');
        $this->form = [
            ...$this->form,
            'name' => (string) $lead->company_name,
            'tax_id' => (string) $lead->tax_id,
            'email' => (string) $lead->contact_email,
            'phone' => (string) $lead->contact_phone,
            'currency' => in_array((string) $lead->currency, $this->currencies, true) ? (string) $lead->currency : $this->form['currency'],
            'contact_first_name' => $first,
            'contact_last_name' => $last,
            'contact_position' => (string) $lead->contact_position,
            'contact_email' => (string) $lead->contact_email,
            'contact_phone' => (string) $lead->contact_phone,
        ];

        // Spec 04 section 17 "Create company from group": the group's name and chat link.
        $chat = $lead->telegram_chat_id !== null && $lead->telegram_chat_id < 0 ? TelegramChat::query()->where('chat_id', $lead->telegram_chat_id)->first() : null;

        if ($chat !== null) {
            $this->form['name'] = $this->form['name'] !== '' ? $this->form['name'] : $chat->displayName();
            $this->form['notes'] = trim(__('telegram.groups.customer_note', ['group' => $chat->displayName(), 'link' => $chat->link() ?? (string) $chat->chat_id]));
        }
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function allowedRoles(): array
    {
        return app(PartnerPolicy::class)->allowedRoles(auth()->user());
    }

    #[Computed]
    public function taxIdMatch(): ?Partner
    {
        return app(DuplicateCustomerFinder::class)->findByTaxId($this->form['tax_id']);
    }

    /**
     * Live duplicate warnings on similar name, email or phone (non-blocking, BR-6).
     *
     * @return Collection<int, array{customer: Customer, reasons: list<string>}>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        return app(DuplicateCustomerFinder::class)
            ->warnings($this->form['name'], $this->form['email'], $this->form['phone'])
            ->filter(fn (array $row): bool => auth()->user()->can('view', $row['customer']))
            ->values();
    }

    public function isUz(): bool
    {
        return strtoupper($this->form['country']) === 'UZ';
    }

    public function legalType(): LegalType
    {
        return LegalType::tryFrom($this->form['legal_type']) ?? LegalType::LegalEntity;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function countries(): array
    {
        $options = Countries::options($this->countrySearch);

        // The chosen country always stays selectable.
        return isset($options[$this->form['country']]) ? $options : [$this->form['country'] => Countries::name($this->form['country'])] + $options;
    }

    /**
     * @return Collection<int, GeoPlace>
     */
    #[Computed]
    public function regions(): Collection
    {
        return GeoPlace::regions();
    }

    /**
     * @return Collection<int, GeoPlace>
     */
    #[Computed]
    public function cities(): Collection
    {
        return GeoPlace::citiesOf($this->form['region_id'] !== '' ? (int) $this->form['region_id'] : null);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function currencies(): array
    {
        $codes = ReferenceItem::options('currency')->pluck('code')->all();

        return $codes !== [] ? $codes : array_keys((array) config('crm.currencies'));
    }

    /**
     * Owners: users with sales access for customers, purchase/logistics users for suppliers and
     * carriers (both when both roles are ticked); the current user is always offered.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function owners(): Collection
    {
        $customer = in_array('customer', $this->roles, true);
        $supplier = array_intersect(['supplier', 'carrier'], $this->roles) !== [];
        $term = mb_strtolower(trim($this->ownerSearch));

        return User::query()->internal()->active()->orderBy('name')->get()
            ->filter(fn (User $user): bool => $user->id === auth()->id()
                || ($customer && (PermissionMap::allows($user, 'customers.create') || PermissionMap::allows($user, 'leads.create')))
                || ($supplier && PermissionMap::allows($user, 'counterparties.manage'))
                || (! $customer && ! $supplier))
            ->filter(fn (User $user): bool => $term === '' || str_contains(mb_strtolower($user->name), $term) || (string) $user->id === $this->form['owner_id'])
            ->values();
    }

    /**
     * @return Collection<int, ReferenceItem>
     */
    #[Computed]
    public function positions(): Collection
    {
        return ReferenceItem::options('contact_position');
    }

    /** Owner other than yourself needs an assign right. */
    public function canChangeOwner(): bool
    {
        $user = auth()->user();

        return PermissionMap::scopeFor($user, 'customers.change_owner') !== null || PermissionMap::allows($user, 'leads.assign') || PermissionMap::allows($user, 'counterparties.qualify');
    }

    public function updatedFormLegalType(): void
    {
        // Decision 14: individual -> retail, everything else -> B2B (overridable).
        $this->form['sale_type'] = $this->legalType() === LegalType::Individual ? SaleType::Retail->value : SaleType::B2b->value;
    }

    public function updatedFormCountry(): void
    {
        $this->form['country'] = strtoupper($this->form['country']);

        // Region/city dropdowns only for Uzbekistan; the typed city is kept.
        if (! $this->isUz()) {
            $this->form['region_id'] = '';
            $this->form['city_id'] = '';
        }

        unset($this->countries);
    }

    public function updatedFormRegionId(): void
    {
        $this->form['city_id'] = '';
        unset($this->cities);
    }

    /** The form's primary action: "Next" until the last step, then "Create". */
    public function submit(CreatePartner $create): void
    {
        $this->step < count(self::STEPS) ? $this->next() : $this->save($create);
    }

    public function next(): void
    {
        $this->authorize('create', Partner::class);
        $this->prepareInputs();
        $key = self::STEPS[$this->step - 1];

        $this->validate(
            array_intersect_key($this->ruleSet(), array_flip(self::STEP_KEYS[$key])),
            $this->validationMessages(),
            $this->attributeNames(),
        );

        if (! $this->stepChecksPass($key)) {
            return;
        }

        $this->step = min(count(self::STEPS), $this->step + 1);
        $this->furthest = max($this->furthest, $this->step);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    /** Only steps already reached can be revisited; moving forward always goes through "Next". */
    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= $this->furthest) {
            $this->step = $step;
        }
    }

    public function save(CreatePartner $create): void
    {
        $this->authorize('create', Partner::class);
        $this->prepareInputs();
        $customer = in_array('customer', $this->roles, true);
        $supplier = array_intersect(['supplier', 'carrier'], $this->roles) !== [];
        $uz = $this->isUz();

        try {
            $this->validate($this->ruleSet(), $this->validationMessages(), $this->attributeNames());
        } catch (ValidationException $exception) {
            $this->showFirstStepWithError(array_keys($exception->errors()));

            throw $exception;
        }

        foreach (['identity', 'terms'] as $key) {
            if (! $this->stepChecksPass($key)) {
                $this->showFirstStepWithError($this->getErrorBag()->keys());

                return;
            }
        }

        $data = $this->form;
        $data['sync_with_1c'] = $customer && $this->syncWith1c;
        $data['industry_id'] = $this->form['industry_id'] !== '' ? (int) $this->form['industry_id'] : null;
        $data['region_id'] = $uz && $this->form['region_id'] !== '' ? (int) $this->form['region_id'] : null;
        $data['city_id'] = $uz && $this->form['city_id'] !== '' ? (int) $this->form['city_id'] : null;
        $data['supplier_category_id'] = $supplier && $this->form['supplier_category_id'] !== '' ? (int) $this->form['supplier_category_id'] : null;

        if (! PermissionMap::allows(auth()->user(), 'customers.commercial_terms')) {
            $data['payment_terms_days'] = '0';
        }

        try {
            $partner = $create->handle([...$data, 'has_tir' => $this->hasTir, 'has_adr' => $this->hasAdr], $this->roles, auth()->user());
        } catch (PartnerException $exception) {
            $this->addError('form.tax_id', $exception->getMessage());
            $this->showFirstStepWithError(['form.tax_id']);

            return;
        }

        $this->rememberPosition($this->form['contact_position']);
        session()->flash('status', __('partners.created', ['name' => $partner->name]));

        if ($this->linkLead($partner)) {
            return;
        }

        $this->redirect(Index::cardUrl($partner, auth()->user()) ?? route('partners.index'), navigate: true);
    }

    /** Links the source lead to the new company and goes back to it; false when there is none or it cannot be linked. */
    protected function linkLead(Partner $partner): bool
    {
        $lead = $this->fromLeadId !== null ? Lead::query()->find($this->fromLeadId) : null;

        if ($lead === null || $lead->customer_id !== null || ! auth()->user()->can('update', $lead) || ! in_array('customer', $this->roles, true)) {
            return false;
        }

        try {
            app(LeadWorkflow::class)->linkCustomer($lead, $partner, $partner->primaryContact);
        } catch (LeadActionException) {
            // The company exists either way; the rep links it on the lead form.
            return false;
        }

        session()->flash('status', __('leads.flash.customer_created', ['name' => $partner->name]));
        $this->redirectRoute('leads.show', $lead, navigate: true);

        return true;
    }

    protected function prepareInputs(): void
    {
        $this->roles = array_values(array_intersect($this->roles, $this->allowedRoles));
        $this->normalizeInputs();
    }

    /**
     * Checks that are not plain field rules; they add their own error and stop the step.
     */
    protected function stepChecksPass(string $key): bool
    {
        if ($key === 'identity' && $this->taxIdError() !== null) {
            $this->addError('form.tax_id', $this->taxIdError());

            return false;
        }

        if ($key === 'terms') {
            if (in_array('customer', $this->roles, true) && $this->syncWith1c && blank($this->form['tax_id'])) {
                $this->addError('syncWith1c', __('customers.errors.sync_needs_tax_id'));

                return false;
            }

            if ((string) $this->form['owner_id'] !== (string) auth()->id() && ! $this->canChangeOwner()) {
                $this->addError('form.owner_id', __('partners.errors.owner_not_allowed'));

                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $errorKeys
     */
    protected function showFirstStepWithError(array $errorKeys): void
    {
        foreach (self::STEPS as $index => $key) {
            if (array_intersect(self::STEP_KEYS[$key], $errorKeys) !== []) {
                $this->step = $index + 1;

                return;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function validationMessages(): array
    {
        return ['roles.required' => __('partners.errors.role_required'), 'roles.min' => __('partners.errors.role_required')];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function ruleSet(): array
    {
        $customer = in_array('customer', $this->roles, true);
        $type = $this->legalType();
        $uz = $this->isUz();

        return [
            'roles' => ['required', 'array', 'min:1'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.legal_type' => ['required', Rule::enum(LegalType::class)],
            'form.legal_name' => [$type->requiresLegalName() ? 'required' : 'nullable', 'string', 'max:255'],
            'form.tax_id' => [$type->requiresTaxId() ? 'required' : 'nullable', 'string', 'max:32'],
            'form.industry_id' => ['nullable', Rule::exists('reference_items', 'id')->where('list', 'industry')],
            'form.website' => ['nullable', 'url:http,https', 'max:255'],
            'form.country' => ['required', Rule::in(Countries::CODES)],
            'form.region_id' => ['nullable', Rule::exists('geo_places', 'id')->where('kind', 'region')],
            'form.city_id' => ['nullable', Rule::exists('geo_places', 'id')->where('kind', 'city')->where('parent_id', $this->form['region_id'] !== '' ? (int) $this->form['region_id'] : 0)],
            'form.city' => ['nullable', 'string', 'max:120'],
            'form.address' => ['nullable', 'string', 'max:2000'],
            'form.postal_code' => $uz ? ['nullable', 'regex:/^\d{6}$/'] : ['nullable', 'string', 'max:20'],
            'form.phone' => ['nullable', 'string', 'max:32'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.preferred_locale' => ['required', Rule::enum(Locale::class)],
            'form.notes' => ['nullable', 'string', 'max:5000'],
            'form.currency' => ['required', Rule::in($this->currencies)],
            'form.sale_type' => [$customer ? 'required' : 'nullable', Rule::enum(SaleType::class)],
            'form.payment_terms_days' => [$customer ? 'required' : 'nullable', 'integer', 'min:0', 'max:365'],
            'form.owner_id' => ['required', 'integer', Rule::in($this->owners->pluck('id')->all())],
            'form.supplier_category_id' => ['nullable', Rule::exists('reference_items', 'id')->where('list', 'supplier_category')],
            'form.fleet_size' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'form.contact_first_name' => ['nullable', 'required_with:form.contact_email,form.contact_phone,form.contact_last_name,form.contact_position', 'string', 'max:120'],
            'form.contact_last_name' => ['nullable', 'string', 'max:120'],
            'form.contact_position' => ['nullable', 'string', 'max:120'],
            'form.contact_email' => ['nullable', 'email', 'max:255'],
            'form.contact_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    /** The tax ID belongs to an existing partner: give it the chosen roles instead of a duplicate. */
    public function addRolesToExisting(ChangePartnerRoles $change): void
    {
        $this->authorize('create', Partner::class);
        // Re-derived from the entered tax ID, never from a client-side id.
        $partner = $this->taxIdMatch;

        if ($partner === null || $partner->trashed()) {
            return;
        }

        $this->authorize('editRoles', $partner);

        try {
            $partner = $change->handle($partner, array_values(array_unique([...$partner->roles(), ...array_intersect($this->roles, $this->allowedRoles)])), auth()->user());
        } catch (PartnerException $exception) {
            $this->addError('roles', $exception->getMessage());

            return;
        }

        session()->flash('status', __('organizations.role_added', ['name' => $partner->name]));
        $this->redirect(Index::cardUrl($partner, auth()->user()) ?? route('partners.index'), navigate: true);
    }

    /**
     * Decision 4: INN (9 digits) for Uzbekistan, a private person may give a 14-digit PINFL instead;
     * other countries any 5-20 letters/digits; no checksum.
     */
    protected function taxIdError(): ?string
    {
        $tax = Customer::normalizeTaxId($this->form['tax_id']);

        if ($tax === null) {
            return null;
        }

        if ($this->isUz()) {
            $valid = $this->legalType() === LegalType::Individual ? preg_match('/^(\d{9}|\d{14})$/', $tax) === 1 : preg_match('/^\d{9}$/', $tax) === 1;

            return $valid ? null : __($this->legalType() === LegalType::Individual ? 'partners.errors.tax_id_uz_individual' : 'customers.errors.tax_id_uz');
        }

        return preg_match('/^[A-Z0-9]{5,20}$/', $tax) === 1 ? null : __('partners.errors.tax_id_foreign');
    }

    protected function normalizeInputs(): void
    {
        $this->form = array_map(fn ($value): string => is_string($value) ? trim($value) : (string) $value, $this->form);
        $this->form['email'] = mb_strtolower($this->form['email']);
        $this->form['contact_email'] = mb_strtolower($this->form['contact_email']);
        $this->form['country'] = strtoupper($this->form['country']);

        if ($this->form['website'] !== '' && preg_match('#^https?://#i', $this->form['website']) !== 1) {
            $this->form['website'] = 'https://'.$this->form['website'];
        }

        if (! $this->isUz()) {
            $this->form['region_id'] = '';
            $this->form['city_id'] = '';
        }
    }

    /** Decision 15: a typed position becomes a suggestion for others (reviewable in Reference lists). */
    protected function rememberPosition(string $position): void
    {
        $position = trim($position);

        if ($position === '' || ReferenceItem::query()->where('list', 'contact_position')->get()->contains(fn (ReferenceItem $item): bool => in_array(mb_strtolower($position), array_map('mb_strtolower', array_values($item->names)), true))) {
            return;
        }

        ReferenceItem::query()->create([
            'list' => 'contact_position', 'code' => 'user_'.substr(md5(mb_strtolower($position)), 0, 10),
            'names' => ['uz' => $position, 'ru' => $position, 'en' => $position], 'is_active' => true, 'sort_order' => 900, 'source' => 'user', 'created_by' => auth()->id(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(): array
    {
        $names = ['roles' => __('partners.fields.roles')];

        foreach (['name', 'legal_name', 'legal_type', 'tax_id', 'country', 'city', 'address', 'postal_code', 'phone', 'email', 'website', 'preferred_locale', 'notes', 'currency', 'sale_type', 'payment_terms_days', 'owner_id'] as $field) {
            $names['form.'.$field] = __('customers.fields.'.$field);
        }

        foreach (['first_name', 'last_name', 'position', 'email', 'phone'] as $field) {
            $names['form.contact_'.$field] = __('customers.contacts.fields.'.$field);
        }

        return [...$names, 'form.industry_id' => __('customers.fields.industry'), 'form.region_id' => __('partners.fields.region'), 'form.city_id' => __('customers.fields.city'), 'form.supplier_category_id' => __('partners.fields.supplier_category'), 'form.fleet_size' => __('partners.fields.fleet_size')];
    }

    public function render(): View
    {
        return view('livewire.partners.form', [
            'legalTypes' => LegalType::options(),
            'locales' => Locale::cases(),
            'industries' => ReferenceItem::options('industry'),
            'supplierCategories' => ReferenceItem::options('supplier_category'),
            'canTerms' => PermissionMap::allows(auth()->user(), 'customers.commercial_terms'),
        ])->title(__('partners.new'));
    }
}
