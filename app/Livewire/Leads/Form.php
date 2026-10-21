<?php

namespace App\Livewire\Leads;

use App\Actions\Leads\SaveLead;
use App\Authorization\PermissionMap;
use App\Enums\LeadPriority;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Locale;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Lead;
use App\Models\LeadDraft;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use App\Services\Leads\Exceptions\LeadActionException;
use App\Services\Leads\LeadDuplicateFinder;
use App\Services\Leads\LeadOwners;
use App\Services\Leads\LeadScore;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UzbekIdentifiers;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lead capture and edit (Spec 04 US-1, BR-2, BR-3, section 16): a two-column page with sectioned
 * cards (company and contact, opportunity, products of interest, qualification, assignment) and a
 * sticky summary (score, duplicates, completeness, next step, save actions).
 *  - Unified search over customers (incl. companies synced from 1C) and open leads; picking a
 *    customer prefills contact, owner (with `leads.assign`), language and sale type and shows its
 *    open offers, last orders and receivables.
 *  - New companies: live duplicate detection, INN format check, phone normalised to +998.
 *  - Exact duplicates need an override reason; owner changes need `leads.assign` (checked again on
 *    save); a follow-up task for tomorrow is proposed; the form autosaves a server-side draft per
 *    user (create mode); save, save and add another, save and create offer.
 *  - Step-by-step mode (Spec 04 section 16.4, default): 1 company and contact, 2 need and items,
 *    3 qualification and assignment, 4 review with edit links and the save buttons; each step is
 *    validated before moving on; "Show everything on one page" switches to the single page and is
 *    remembered per user. The state lives in the component, so validation errors lose nothing.
 */
#[Layout('layouts.app')]
class Form extends Component
{
    #[Locked]
    public ?int $leadId = null;

    public string $search = '';

    public string $customerSearch = '';

    public ?int $customerId = null;

    public ?int $contactId = null;

    public string $company_name = '';

    public string $tax_id = '';

    public string $industry = '';

    public string $contact_name = '';

    public string $contact_position = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $source = 'phone';

    public string $campaign = '';

    public string $need = '';

    public string $est_value = '';

    public string $currency = 'UZS';

    public string $expected_close_date = '';

    public string $priority = 'normal';

    public string $sale_type = '';

    public string $locale = '';

    public bool $budget_confirmed = false;

    public bool $authority_confirmed = false;

    public bool $need_confirmed = false;

    public bool $timeline_confirmed = false;

    public ?int $owner_id = null;

    public string $itemSearch = '';

    /** @var list<array{item_id: int, name: string, sku: string, unit: string, quantity: string}> */
    public array $items = [];

    public bool $followUp = true;

    public string $followUpDue = '';

    public string $followUpNote = '';

    public string $duplicateOverride = '';

    /** A stored draft can be restored (create mode). */
    #[Locked]
    public ?string $draftSavedAt = null;

    #[Locked]
    public string $draftHash = '';

    /** Wizard step: 1 company and contact, 2 need and items, 3 qualification, 4 review. */
    public int $step = 1;

    /** "Show everything on one page" (remembered per user). */
    #[Locked]
    public bool $singlePage = false;

    /** Highest step reached; the indicator links to steps up to it. */
    #[Locked]
    public int $reachedStep = 1;

    public const STEPS = [1 => 'company', 2 => 'need', 3 => 'qualify', 4 => 'review'];

    /** Fields validated on each step (the review step validates everything on save). */
    protected const STEP_FIELDS = [
        1 => ['customerId', 'contactId', 'company_name', 'tax_id', 'industry', 'contact_name', 'contact_position', 'contact_email', 'contact_phone', 'source', 'campaign', 'sale_type', 'locale'],
        2 => ['need', 'est_value', 'currency', 'expected_close_date', 'priority', 'items.*.quantity'],
        3 => ['owner_id', 'followUpDue', 'followUpNote'],
    ];

    public function mount(?Lead $lead = null): void
    {
        $this->singlePage = (bool) auth()->user()->uiPreference('lead_form_single_page', false);

        if ($lead?->exists) {
            $this->authorize('update', $lead);
            $this->leadId = $lead->id;
            $this->fillFrom($lead);
            $this->followUp = false;
            // Editing: every step is already filled, so the indicator links to all of them.
            $this->reachedStep = 4;

            return;
        }

        $this->authorize('create', Lead::class);
        $this->currency = (string) config('crm.base_currency', 'UZS');
        $this->owner_id = auth()->id();
        $this->followUpDue = CarbonImmutable::tomorrow((string) config('offers.timezone', 'Asia/Tashkent'))->setTime(10, 0)->format('Y-m-d\TH:i');
        $this->draftSavedAt = LeadDraft::query()->where('user_id', auth()->id())->value('updated_at')?->toIso8601String();

        // Quick action from the customer card (?customer=): only customers the user may view.
        $prefill = request()->query('customer');

        if (is_string($prefill) && ctype_digit($prefill)) {
            $this->selectCustomer((int) $prefill);
        }
    }

    protected function fillFrom(Lead $lead): void
    {
        $this->customerId = $lead->customer_id;
        $this->contactId = $lead->contact_id;

        foreach (['company_name', 'tax_id', 'industry', 'contact_name', 'contact_position', 'contact_email', 'contact_phone', 'campaign', 'need'] as $field) {
            $this->{$field} = (string) $lead->{$field};
        }

        foreach (Lead::BANT as $flag) {
            $this->{$flag} = (bool) $lead->{$flag};
        }

        $this->source = $lead->source->value;
        $this->currency = $lead->currency;
        $this->priority = $lead->priority?->value ?? 'normal';
        $this->sale_type = (string) $lead->sale_type?->value;
        $this->locale = (string) $lead->locale;
        $this->est_value = $lead->estimatedValue()?->toMajor() ?? '';
        $this->expected_close_date = (string) $lead->expected_close_date?->toDateString();
        $this->owner_id = $lead->owner_id;
        $this->items = $lead->items()->with('item.unit')->get()->map(fn ($row): array => [
            'item_id' => $row->item_id,
            'name' => (string) $row->item?->name,
            'sku' => (string) $row->item?->sku,
            'unit' => (string) $row->item?->unit?->code,
            'quantity' => Quantity::trimmed((string) $row->quantity),
        ])->all();
    }

    #[Computed]
    public function lead(): ?Lead
    {
        return $this->leadId === null ? null : Lead::query()->findOrFail($this->leadId);
    }

    /** Field policy: another owner only with `leads.assign`. */
    public function canAssign(): bool
    {
        $user = auth()->user();

        return $this->lead !== null ? $user->can('assign', $this->lead) : PermissionMap::allows($user, 'leads.assign');
    }

    /**
     * Unified search (section 16): customers (CRM and 1C-synced) and open leads by name, tax ID,
     * phone or e-mail.
     *
     * @return array{customers: Collection<int, Customer>, leads: Collection<int, Lead>}
     */
    #[Computed]
    public function searchResults(): array
    {
        $term = trim($this->search !== '' ? $this->search : $this->customerSearch);

        if (mb_strlen($term) < 2) {
            return ['customers' => new Collection, 'leads' => new Collection];
        }

        $like = '%'.$term.'%';
        $digits = (string) preg_replace('/\D/', '', $term);

        $customers = Customer::query()
            ->visibleTo(auth()->user())
            ->notDeletedInOneC()
            ->where(function ($q) use ($like, $digits): void {
                $q->where('name', 'like', $like)->orWhere('legal_name', 'like', $like)->orWhere('email', 'like', mb_strtolower($like));

                if (strlen($digits) >= 3) {
                    $q->orWhere('tax_id', 'like', '%'.$digits.'%')->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')->limit(8)->get(['id', 'name', 'tax_id', 'city', 'origin', 'owner_id', 'sale_type']);

        $leads = Lead::query()
            ->visibleTo(auth()->user())
            ->whereIn('status', LeadStatus::openValues())
            ->when($this->leadId !== null, fn ($q) => $q->whereKeyNot($this->leadId))
            ->where(function ($q) use ($like, $digits): void {
                $q->where('company_name', 'like', $like)->orWhere('contact_name', 'like', $like)->orWhere('contact_email', 'like', mb_strtolower($like));

                if (strlen($digits) >= 3) {
                    $q->orWhere('tax_id', 'like', '%'.$digits.'%')->orWhere('contact_phone', 'like', '%'.$digits.'%');
                }
            })
            ->orderByDesc('id')->limit(5)->get(['id', 'number', 'status', 'company_name', 'customer_id', 'owner_id']);

        return ['customers' => $customers, 'leads' => $leads];
    }

    /**
     * Backwards-compatible list used by the old picker markup and tests.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        return $this->searchResults['customers'];
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customerId === null ? null : Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()->find($this->customerId);
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return $this->customer?->contacts()->orderByDesc('is_primary')->orderBy('first_name')->get() ?? new Collection;
    }

    /**
     * Context of the picked customer: open offers, last orders, receivable.
     *
     * @return array{offers: Collection<int, Offer>, orders: Collection<int, Order>, outstanding_minor: int, overdue: int, currency: string}|null
     */
    #[Computed]
    public function customerContext(): ?array
    {
        $customer = $this->customer;

        if ($customer === null) {
            return null;
        }

        $user = auth()->user();
        $activeOrders = Order::query()->where('customer_id', $customer->id)->whereNotIn('status', [OrderStatus::New, OrderStatus::Closed, OrderStatus::Cancelled])->get(['id', 'gross_minor', 'paid_minor', 'payment_status', 'currency']);

        return [
            'offers' => Offer::query()->visibleTo($user)->where('customer_id', $customer->id)->whereIn('status', [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Approved, OfferStatus::Sent, OfferStatus::Viewed, OfferStatus::PartiallySelected])->latest('id')->limit(3)->get(),
            'orders' => Order::query()->where('customer_id', $customer->id)->latest('id')->limit(3)->get(['id', 'number', 'status', 'gross_minor', 'currency', 'created_at']),
            'outstanding_minor' => (int) $activeOrders->where('currency', $customer->currency)->sum(fn (Order $order): int => max(0, $order->gross_minor - $order->paid_minor)),
            'overdue' => $activeOrders->filter(fn (Order $order): bool => $order->payment_status?->value === 'overdue')->count(),
            'currency' => $customer->currency,
        ];
    }

    /**
     * @return SupportCollection<int, User>
     */
    #[Computed]
    public function assignableOwners(): SupportCollection
    {
        return app(LeadOwners::class)->assignableBy(auth()->user());
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemResults(): Collection
    {
        $term = trim($this->itemSearch);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        return Item::query()->sellable()->with(['unit', 'stocks'])
            ->whereNotIn('id', array_column($this->items, 'item_id'))
            ->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')->orWhere('article', 'like', '%'.$term.'%'))
            ->orderBy('name')->limit(10)->get();
    }

    /**
     * @return SupportCollection<int, array{customer: Customer, reasons: list<string>}>
     */
    #[Computed]
    public function duplicateCustomers(): SupportCollection
    {
        if ($this->customerId !== null) {
            return collect();
        }

        return app(LeadDuplicateFinder::class)->customers($this->tax_id, $this->company_name, $this->contact_email, $this->contact_phone);
    }

    /**
     * @return Collection<int, Lead>|SupportCollection<int, Lead>
     */
    #[Computed]
    public function duplicateLeads(): Collection|SupportCollection
    {
        return app(LeadDuplicateFinder::class)->openLeads($this->tax_id, $this->company_name, $this->contact_email, $this->contact_phone, $this->customerId, $this->leadId);
    }

    #[Computed]
    public function needsOverride(): bool
    {
        return app(LeadDuplicateFinder::class)->needsOverride($this->tax_id, $this->contact_email, $this->contact_phone, $this->customerId, $this->leadId);
    }

    /** Live phone preview ("will be saved as"). */
    #[Computed]
    public function normalizedPhone(): ?string
    {
        return UzbekIdentifiers::normalizePhone($this->contact_phone);
    }

    #[Computed]
    public function taxIdError(): ?string
    {
        return $this->customerId === null ? UzbekIdentifiers::taxIdError($this->tax_id) : null;
    }

    /**
     * Summary panel: score, band, completeness and the gaps that block qualification.
     *
     * @return array{score: int, band: string, completeness: int, gaps: list<string>, suggestion: string}
     */
    #[Computed]
    public function summary(): array
    {
        $scorer = app(LeadScore::class);
        $estimated = null;

        try {
            $estimated = blank($this->est_value) ? null : Money::fromMajor($this->est_value, $this->currency)->amount;
        } catch (InvalidArgumentException) {
            $estimated = null;
        }

        $reachable = $this->contactId !== null || filled($this->contact_email) || filled($this->contact_phone);
        $score = $scorer->calculate([
            'budget_confirmed' => $this->budget_confirmed, 'authority_confirmed' => $this->authority_confirmed,
            'need_confirmed' => $this->need_confirmed, 'timeline_confirmed' => $this->timeline_confirmed,
            'customer' => $this->customerId !== null, 'reachable' => $reachable, 'need' => $this->need,
            'est_value_minor' => $estimated, 'expected_close_date' => $this->expected_close_date, 'items' => count($this->items), 'priority' => $this->priority,
        ]);

        $gaps = [];

        if ($this->customerId === null) {
            $gaps[] = 'customer';
        }

        if ($this->contactId === null) {
            $gaps[] = 'contact';
        }

        if (blank($this->need)) {
            $gaps[] = 'need';
        }

        foreach (Lead::BANT as $flag) {
            if (! $this->{$flag}) {
                $gaps[] = $flag;
            }
        }

        $checks = ['company' => $this->customerId !== null || filled($this->company_name), 'reach' => $reachable, 'need' => filled($this->need), 'value' => $estimated !== null,
            'close' => filled($this->expected_close_date), 'items' => $this->items !== [], 'bant' => $this->budget_confirmed && $this->authority_confirmed && $this->need_confirmed && $this->timeline_confirmed];

        return [
            'score' => $score,
            'band' => $scorer->band($score),
            'completeness' => (int) round(100 * count(array_filter($checks)) / count($checks)),
            'gaps' => $gaps,
            'suggestion' => $scorer->suggestion($score, $gaps, $this->customerId !== null),
        ];
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()->find($customerId);

        if ($customer === null) {
            return;
        }

        $this->customerId = $customer->id;
        $this->contactId = $customer->primaryContact?->id;
        $this->search = '';
        $this->customerSearch = '';
        $this->sale_type = $customer->sale_type->value;
        $this->locale = (string) Locale::fromAvailable($customer->preferred_locale)?->value;

        if ($this->canAssign() && $this->assignableOwners->contains('id', $customer->owner_id)) {
            $this->owner_id = $customer->owner_id;
        }

        if ($this->leadId === null || $this->est_value === '') {
            $this->currency = $customer->currency;
        }

        if ($this->source === 'phone' && $this->leadId === null) {
            $this->source = LeadSource::ExistingCustomer->value;
        }

        unset($this->customer, $this->contacts, $this->duplicateCustomers, $this->duplicateLeads, $this->needsOverride, $this->customerContext, $this->searchResults, $this->customers);
    }

    public function clearCustomer(): void
    {
        $this->customerId = null;
        $this->contactId = null;
        unset($this->customer, $this->contacts, $this->duplicateCustomers, $this->duplicateLeads, $this->needsOverride, $this->customerContext);
    }

    public function addItem(int $itemId): void
    {
        if (count($this->items) >= (int) config('leads.max_items', 50) || in_array($itemId, array_column($this->items, 'item_id'), true)) {
            return;
        }

        $item = Item::query()->sellable()->with('unit')->find($itemId);

        if ($item === null) {
            return;
        }

        $this->items[] = ['item_id' => $item->id, 'name' => $item->name, 'sku' => (string) $item->sku, 'unit' => (string) $item->unit?->code, 'quantity' => '1'];
        $this->itemSearch = '';
        unset($this->itemResults);
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    /**
     * Server-side draft (create mode): polled; written only when the form changed.
     */
    public function autosave(): void
    {
        if ($this->leadId !== null || ! auth()->user()->can('create', Lead::class)) {
            return;
        }

        $payload = $this->draftPayload();
        $hash = hash('sha256', (string) json_encode($payload));

        if ($hash === $this->draftHash || $this->isPristine($payload)) {
            return;
        }

        LeadDraft::query()->updateOrCreate(['user_id' => auth()->id()], ['payload' => $payload]);
        $this->draftHash = $hash;
    }

    public function restoreDraft(): void
    {
        $draft = LeadDraft::query()->where('user_id', auth()->id())->first();

        if ($draft === null || $this->leadId !== null) {
            return;
        }

        foreach ($draft->payload as $field => $value) {
            if (in_array($field, self::DRAFT_FIELDS, true)) {
                $this->{$field} = $value;
            }
        }

        if (! $this->canAssign()) {
            $this->owner_id = auth()->id();
        }

        $this->draftSavedAt = null;
        unset($this->customer, $this->contacts, $this->summary);
    }

    public function discardDraft(): void
    {
        LeadDraft::query()->where('user_id', auth()->id())->delete();
        $this->draftSavedAt = null;
    }

    private const DRAFT_FIELDS = [
        'customerId', 'contactId', 'company_name', 'tax_id', 'industry', 'contact_name', 'contact_position', 'contact_email', 'contact_phone',
        'source', 'campaign', 'need', 'est_value', 'currency', 'expected_close_date', 'priority', 'sale_type', 'locale', 'budget_confirmed',
        'authority_confirmed', 'need_confirmed', 'timeline_confirmed', 'owner_id', 'items', 'followUp', 'followUpDue', 'followUpNote',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function draftPayload(): array
    {
        $payload = [];

        foreach (self::DRAFT_FIELDS as $field) {
            $payload[$field] = $this->{$field};
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isPristine(array $payload): bool
    {
        return $payload['customerId'] === null && $payload['company_name'] === '' && $payload['contact_name'] === '' && $payload['contact_phone'] === ''
            && $payload['contact_email'] === '' && $payload['need'] === '' && $payload['items'] === [];
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        $lead = $this->lead;

        return [
            'customerId' => ['nullable', 'integer'],
            'contactId' => ['nullable', 'integer', Rule::exists(Contact::class, 'id')->where('partner_id', $this->customerId)->whereNull('deleted_at')],
            'company_name' => [Rule::requiredIf($this->customerId === null), 'nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:20'],
            'industry' => ['nullable', 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_position' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^[\d\s()+-]{5,32}$/'],
            'source' => ['required', Rule::enum(LeadSource::class)],
            'campaign' => ['nullable', 'string', 'max:120'],
            'need' => ['nullable', 'string', 'max:5000'],
            'est_value' => ['nullable', 'string', 'max:30'],
            'currency' => ['required', Rule::in(array_keys((array) config('crm.currencies')))],
            'expected_close_date' => ['nullable', 'date'],
            'priority' => ['required', Rule::enum(LeadPriority::class)],
            'sale_type' => ['nullable', Rule::enum(SaleType::class)],
            'locale' => ['nullable', Rule::enum(Locale::class)],
            'items.*.quantity' => ['required', 'string', 'max:20'],
            'owner_id' => ['nullable', 'integer'],
            'followUpDue' => [Rule::requiredIf($this->followUp && $lead === null), 'nullable', 'date', 'after:now'],
            'followUpNote' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'company_name' => __('leads.fields.company_name'),
            'contact_email' => __('leads.fields.contact_email'),
            'contact_phone' => __('leads.fields.contact_phone'),
            'contactId' => __('leads.fields.contact'),
            'currency' => __('leads.fields.currency'),
            'items.*.quantity' => __('leads.fields.quantity'),
            'followUpDue' => __('leads.fields.follow_up_due'),
        ];
    }

    /**
     * Wizard indicator: step number => [key, done|current|todo].
     *
     * @return array<int, array{0: string, 1: string}>
     */
    #[Computed]
    public function stepStates(): array
    {
        $states = [];

        foreach (self::STEPS as $number => $key) {
            $states[$number] = [$key, $number < $this->step ? 'done' : ($number === $this->step ? 'current' : 'todo')];
        }

        return $states;
    }

    /**
     * Review step: what will be saved, per step (label => value).
     *
     * @return array<int, array<string, string>>
     */
    #[Computed]
    public function reviewRows(): array
    {
        $contact = $this->contactId !== null ? $this->contacts->firstWhere('id', $this->contactId)?->fullName() : $this->contact_name;
        $bant = collect(Lead::BANT)->filter(fn (string $flag): bool => (bool) $this->{$flag})->map(fn (string $flag): string => __('leads.gate.'.$flag))->join(', ');
        $owner = $this->canAssign() ? $this->assignableOwners->firstWhere('id', $this->owner_id)?->name : auth()->user()->name;
        $dash = fn (?string $value): string => filled($value) ? (string) $value : '—';

        return [
            1 => [
                __('leads.sections.company') => $dash($this->customer?->name ?? $this->company_name),
                __('leads.fields.contact_name') => $dash($contact),
                __('leads.fields.contact_phone') => $dash($this->contact_phone),
                __('leads.fields.source') => LeadSource::tryFrom($this->source)?->label() ?? $this->source,
            ],
            2 => [
                __('leads.fields.need') => $dash(Str::limit($this->need, 160)),
                __('leads.fields.est_value') => filled($this->est_value) ? $this->est_value.' '.$this->currency : '—',
                __('leads.fields.expected_close_date') => $dash($this->expected_close_date),
                __('leads.fields.items') => (string) count($this->items),
            ],
            3 => [
                __('leads.sections.bant') => $dash($bant),
                __('leads.fields.owner') => $dash($owner),
                __('leads.fields.follow_up_due') => $this->leadId === null && $this->followUp ? $dash($this->followUpDue) : '—',
            ],
        ];
    }

    /** Step of a field (error key), for jumping back to the first invalid step. */
    public static function stepOf(string $key): int
    {
        foreach (self::STEP_FIELDS as $step => $fields) {
            foreach ($fields as $field) {
                if ($key === $field || ($field === 'items.*.quantity' && str_starts_with($key, 'items.'))) {
                    return $step;
                }
            }
        }

        return 4;
    }

    /** Validates the current step and moves on (wizard mode). */
    public function nextStep(): void
    {
        if ($this->step >= 4) {
            return;
        }

        if (! $this->validateStep($this->step)) {
            return;
        }

        $this->step++;
        $this->reachedStep = max($this->reachedStep, $this->step);
        $this->dispatch('lead-step-changed', step: $this->step);
    }

    public function previousStep(): void
    {
        $this->step = max(1, $this->step - 1);
        $this->dispatch('lead-step-changed', step: $this->step);
    }

    /** Jump to a step already reached (indicator and review edit links). */
    public function goToStep(int $step): void
    {
        if ($step < 1 || $step > 4 || $step > $this->reachedStep) {
            return;
        }

        $this->step = $step;
        $this->dispatch('lead-step-changed', step: $this->step);
    }

    /** "Show everything on one page" switch, remembered per user. */
    public function toggleSinglePage(): void
    {
        $this->singlePage = ! $this->singlePage;
        auth()->user()->setUiPreference('lead_form_single_page', $this->singlePage);
    }

    protected function validateStep(int $step): bool
    {
        $this->resetErrorBag();
        $rules = array_intersect_key($this->rules(), array_flip(self::STEP_FIELDS[$step] ?? []));
        $this->validate($rules, [], $this->validationAttributes());

        if ($step === 1) {
            if ($this->customerId !== null && $this->customer === null) {
                $this->addError('customerId', __('leads.errors.customer_unavailable'));
            } elseif (($taxError = $this->taxIdError) !== null) {
                $this->addError('tax_id', __('leads.tax_id_errors.'.$taxError));
            }
        }

        if ($step === 2) {
            $this->checkMoneyAndItems();
        }

        return $this->getErrorBag()->isEmpty();
    }

    /** Estimated value and item quantities (shared by step 2 and save); false when invalid. */
    protected function checkMoneyAndItems(): bool
    {
        try {
            $estimated = blank($this->est_value) ? null : Money::fromMajor($this->est_value, $this->currency)->amount;
        } catch (InvalidArgumentException) {
            $this->addError('est_value', __('validation.numeric', ['attribute' => __('leads.fields.est_value')]));

            return false;
        }

        if ($estimated !== null && $estimated < 0) {
            $this->addError('est_value', __('validation.min.numeric', ['attribute' => __('leads.fields.est_value'), 'min' => 0]));

            return false;
        }

        foreach ($this->items as $index => $row) {
            $quantity = Quantity::normalize((string) $row['quantity']);

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1) {
                $this->addError('items.'.$index.'.quantity', __('validation.numeric', ['attribute' => __('leads.fields.quantity')]));

                return false;
            }

            if (bccomp($quantity, '0', 3) <= 0) {
                $this->addError('items.'.$index.'.quantity', __('validation.gt.numeric', ['attribute' => __('leads.fields.quantity'), 'value' => 0]));

                return false;
            }
        }

        return true;
    }

    /**
     * Save from any step (Alt+S works in both modes); with errors, the wizard jumps to the first
     * step that has one.
     *
     * @param  'stay'|'another'|'offer'  $then
     */
    public function save(SaveLead $save, string $then = 'stay'): void
    {
        try {
            $this->persist($save, $then);
        } catch (ValidationException $exception) {
            $this->jumpToErrors(array_keys($exception->errors()));

            throw $exception;
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->jumpToErrors($this->getErrorBag()->keys());
        }
    }

    /**
     * @param  list<string>  $keys
     */
    protected function jumpToErrors(array $keys): void
    {
        if ($this->singlePage || $keys === []) {
            return;
        }

        $this->step = min(array_map(fn (string $key): int => self::stepOf($key), $keys));
        $this->reachedStep = max($this->reachedStep, $this->step);
        $this->dispatch('lead-step-changed', step: $this->step);
    }

    /**
     * @param  'stay'|'another'|'offer'  $then
     */
    protected function persist(SaveLead $save, string $then): void
    {
        $then = in_array($then, ['stay', 'another', 'offer'], true) ? $then : 'stay';
        $lead = $this->lead;
        $lead !== null ? $this->authorize('update', $lead) : $this->authorize('create', Lead::class);

        $this->validate($this->rules(), [], $this->validationAttributes());

        if ($this->customerId !== null && $this->customer === null) {
            $this->addError('customerId', __('leads.errors.customer_unavailable'));

            return;
        }

        if (($taxError = $this->taxIdError) !== null) {
            $this->addError('tax_id', __('leads.tax_id_errors.'.$taxError));

            return;
        }

        if ($this->needsOverride && mb_strlen(trim($this->duplicateOverride)) < 10) {
            $this->addError('duplicateOverride', __('leads.errors.duplicate_override'));

            return;
        }

        try {
            $estimated = blank($this->est_value) ? null : Money::fromMajor($this->est_value, $this->currency)->amount;
        } catch (InvalidArgumentException) {
            $this->addError('est_value', __('validation.numeric', ['attribute' => __('leads.fields.est_value')]));

            return;
        }

        if ($estimated !== null && $estimated < 0) {
            $this->addError('est_value', __('validation.min.numeric', ['attribute' => __('leads.fields.est_value'), 'min' => 0]));

            return;
        }

        $items = [];

        foreach ($this->items as $index => $row) {
            $quantity = Quantity::normalize((string) $row['quantity']);

            if (preg_match('/^\d{1,12}(\.\d{1,3})?$/', $quantity) !== 1) {
                $this->addError('items.'.$index.'.quantity', __('validation.numeric', ['attribute' => __('leads.fields.quantity')]));

                return;
            }

            if (bccomp($quantity, '0', 3) <= 0) {
                $this->addError('items.'.$index.'.quantity', __('validation.gt.numeric', ['attribute' => __('leads.fields.quantity'), 'value' => 0]));

                return;
            }

            $items[(int) $row['item_id']] = $quantity;
        }

        $data = [
            'source' => $this->source, 'campaign' => $this->campaign, 'customer_id' => $this->customerId, 'contact_id' => $this->contactId,
            'company_name' => $this->company_name, 'tax_id' => $this->tax_id, 'industry' => $this->industry, 'contact_name' => $this->contact_name,
            'contact_position' => $this->contact_position, 'contact_email' => $this->contact_email, 'contact_phone' => $this->contact_phone,
            'need' => $this->need, 'est_value_minor' => $estimated, 'currency' => $this->currency, 'expected_close_date' => $this->expected_close_date ?: null,
            'budget_confirmed' => $this->budget_confirmed, 'authority_confirmed' => $this->authority_confirmed,
            'need_confirmed' => $this->need_confirmed, 'timeline_confirmed' => $this->timeline_confirmed,
            'priority' => $this->priority, 'sale_type' => $this->sale_type ?: null, 'locale' => $this->locale ?: null,
            'duplicate_override_reason' => $this->needsOverride ? trim($this->duplicateOverride) : null,
        ];

        // Field policy (section 16): another owner only with `leads.assign`, within the assigner's scope.
        if ($this->owner_id !== null && $this->owner_id !== ($lead?->owner_id ?? auth()->id())) {
            if (! $this->canAssign() || ! $this->assignableOwners->contains('id', $this->owner_id)) {
                $this->addError('owner_id', __('leads.errors.owner_invalid'));

                return;
            }

            $data['owner_id'] = $this->owner_id;
        }

        $followUp = $this->followUp && $lead === null ? ['due_at' => $this->followUpDue, 'note' => $this->followUpNote, 'remind' => true] : null;

        try {
            $saved = $save->handle(auth()->user(), $data, $items, $lead, $followUp);
        } catch (LeadActionException $exception) {
            $this->addError(match ($exception->key) {
                'company_required' => 'company_name',
                'reach_required' => 'contact_phone',
                'contact_mismatch' => 'contactId',
                default => 'customerId',
            }, $exception->getMessage());

            return;
        }

        if ($lead === null) {
            LeadDraft::query()->where('user_id', auth()->id())->delete();
        }

        session()->flash('status', $lead === null ? __('leads.flash.created', ['number' => $saved->number]) : __('leads.flash.updated'));

        if ($then === 'another') {
            $this->redirectRoute('leads.create', navigate: true);

            return;
        }

        if ($then === 'offer') {
            $this->createOffer($saved);

            return;
        }

        $this->redirectRoute('leads.show', $saved, navigate: true);
    }

    /**
     * "Save and convert to customer" (Spec 04 section 18; offers go only to partners): opens the
     * lead page with the conversion dialog; the first offer is created from there.
     */
    protected function createOffer(Lead $lead): void
    {
        $this->redirectRoute('leads.show', ['lead' => $lead, 'panel' => 'convert'], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.leads.form', [
            'sources' => LeadSource::options(),
            'priorities' => LeadPriority::options(),
            'saleTypes' => SaleType::options(),
            'locales' => Locale::cases(),
            'currencies' => array_keys((array) config('crm.currencies')),
        ])->title($this->leadId === null ? __('leads.new') : __('leads.edit_title', ['number' => $this->lead?->number]));
    }
}
