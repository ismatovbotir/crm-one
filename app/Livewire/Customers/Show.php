<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\SaveContact;
use App\Actions\Leads\CreateOfferFromInterests;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\IntegrationFeed;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Offer;
use App\Models\Partner;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\OneC\Customers\CustomerOutbox;
use App\Services\OneC\OneCSettings;
use App\Services\Organizations\OrganizationRoles;
use App\Services\Timeline\CustomerTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Customer detail: header, contacts (with primary), activity; archive / restore (Spec 01 section 7).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $customerId;

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    /** Contact modal state. */
    public bool $showContactForm = false;

    #[Locked]
    public ?int $contactId = null;

    public string $first_name = '';

    public string $last_name = '';

    public string $position = '';

    public string $email = '';

    public string $phone = '';

    public bool $is_primary = false;

    public string $contact_notes = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('view', $customer);

        $this->customerId = $customer->id;

        if (! in_array($this->tab, ['overview', 'contacts', 'finance', 'portal', 'activity', 'timeline', 'telegram', 'equipment'], true)) {
            $this->tab = 'overview';
        }
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::withTrashed()->with(['owner', 'primaryContact'])->findOrFail($this->customerId);
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return $this->customer->contacts()->orderByDesc('is_primary')->orderBy('first_name')->get();
    }

    /**
     * Change log of the customer and its contacts, newest first.
     *
     * @return Collection<int, ActivityLog>
     */
    /**
     * Customer 360 timeline (roadmap step 5): record changes and the history of its leads, offers,
     * orders and agreements.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function timeline(): \Illuminate\Support\Collection
    {
        return app(CustomerTimeline::class)->entries($this->customer, auth()->user());
    }

    #[Computed]
    public function activities(): Collection
    {
        if (! auth()->user()->can('viewActivity', $this->customer)) {
            return new Collection;
        }

        $contactIds = Contact::withTrashed()->where('partner_id', $this->customerId)->pluck('id');

        return ActivityLog::query()
            ->with('causer')
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('subject_type', 'partner')->where('subject_id', $this->customerId))
                ->orWhere(fn ($q) => $q->where('subject_type', 'contact')->whereIn('subject_id', $contactIds)))
            ->latest('created_at')
            ->latest('id')
            ->limit(100)
            ->get();
    }

    public function newContact(): void
    {
        $this->authorize('manageContacts', $this->customer);

        $this->resetContactForm();
        $this->is_primary = $this->contacts->where('is_primary', true)->isEmpty();
        $this->showContactForm = true;
    }

    public function editContact(int $contactId): void
    {
        $this->authorize('manageContacts', $this->customer);

        $contact = $this->findContact($contactId);
        $this->resetContactForm();

        $this->contactId = $contact->id;
        $this->first_name = $contact->first_name;
        $this->last_name = (string) $contact->last_name;
        $this->position = (string) $contact->position;
        $this->email = (string) $contact->email;
        $this->phone = (string) $contact->phone;
        $this->is_primary = $contact->is_primary;
        $this->contact_notes = (string) $contact->notes;
        $this->showContactForm = true;
    }

    public function cancelContact(): void
    {
        $this->resetContactForm();
    }

    public function saveContact(): void
    {
        $this->authorize('manageContacts', $this->customer);

        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'required_without:phone', 'email', 'max:255'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:32'],
            'is_primary' => ['boolean'],
            'contact_notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'email.required_without' => __('customers.contacts.errors.email_or_phone'),
            'phone.required_without' => __('customers.contacts.errors.email_or_phone'),
        ], [
            'first_name' => __('customers.contacts.fields.first_name'),
            'last_name' => __('customers.contacts.fields.last_name'),
            'position' => __('customers.contacts.fields.position'),
            'email' => __('customers.contacts.fields.email'),
            'phone' => __('customers.contacts.fields.phone'),
        ]);

        $contact = $this->contactId === null ? null : $this->findContact($this->contactId);

        $saved = app(SaveContact::class)->handle($this->customer, [
            'first_name' => trim($validated['first_name']),
            'last_name' => filled($validated['last_name']) ? trim($validated['last_name']) : null,
            'position' => filled($validated['position']) ? trim($validated['position']) : null,
            'email' => filled($validated['email']) ? trim($validated['email']) : null,
            'phone' => filled($validated['phone']) ? trim($validated['phone']) : null,
            'is_primary' => $validated['is_primary'],
            'notes' => filled($validated['contact_notes']) ? trim($validated['contact_notes']) : null,
        ], $contact);

        $this->resetContactForm();
        unset($this->contacts, $this->customer, $this->activities);
        session()->flash('status', __($contact === null ? 'customers.contacts.created' : 'customers.contacts.updated', ['name' => $saved->fullName()]));
    }

    public function makePrimary(int $contactId): void
    {
        $this->authorize('manageContacts', $this->customer);

        $contact = $this->findContact($contactId);

        app(SaveContact::class)->handle($this->customer, ['first_name' => $contact->first_name, 'is_primary' => true], $contact);

        unset($this->contacts, $this->customer, $this->activities);
        session()->flash('status', __('customers.contacts.primary_set', ['name' => $contact->fullName()]));
    }

    public function archiveContact(int $contactId): void
    {
        $this->authorize('manageContacts', $this->customer);

        $contact = $this->findContact($contactId);
        $wasPrimary = $contact->is_primary;
        $contact->delete();

        unset($this->contacts, $this->customer, $this->activities);
        session()->flash('status', $wasPrimary
            ? __('customers.contacts.archived_primary', ['name' => $contact->fullName()])
            : __('customers.contacts.archived', ['name' => $contact->fullName()]));
    }

    /**
     * Re-queue the customer in the 1C outbox (Spec 02 rev. 3): 1C collects it on its next poll.
     */
    public function resync(CustomerOutbox $outbox): void
    {
        $customer = $this->customer;
        $this->authorize('resync', $customer);

        $entry = $outbox->queue($customer, CustomerOutboxEntry::REASON_RESYNC, auth()->user());

        unset($this->customer, $this->activities);

        if ($entry === null) {
            session()->flash('error', __('integration.customer_sync.not_pushable.'.($customer->fresh()?->sync_error ?? 'integration_disabled')));

            return;
        }

        session()->flash('status', __('integration.customer_sync.resync_queued'));
    }

    /**
     * Last time 1C polled the outbox and whether that is too long ago (BR-I.23).
     *
     * @return array{last_poll: CarbonImmutable|null, is_stale: bool, minutes: int, enabled: bool}
     */
    #[Computed]
    public function exchange(): array
    {
        $settings = app(OneCSettings::class);

        return [
            'last_poll' => IntegrationFeed::lastContact(IntegrationFeed::CUSTOMERS_POLL),
            'is_stale' => $settings->customerPollIsStale(),
            'minutes' => $settings->customerPollStaleMinutes(),
            'enabled' => $settings->customersEnabled(),
        ];
    }

    public function archive(): void
    {
        $customer = $this->customer;
        $this->authorize('archive', $customer);

        $customer->delete();

        unset($this->customer);
        session()->flash('status', __('customers.archived', ['name' => $customer->name]));
    }

    public function restore(): void
    {
        $customer = $this->customer;
        $this->authorize('restore', $customer);

        $customer->restore();

        unset($this->customer);
        session()->flash('status', __('customers.restored', ['name' => $customer->name]));
    }

    protected function findContact(int $contactId): Contact
    {
        return $this->customer->contacts()->whereKey($contactId)->firstOrFail();
    }

    protected function resetContactForm(): void
    {
        $this->reset(['showContactForm', 'contactId', 'first_name', 'last_name', 'position', 'email', 'phone', 'is_primary', 'contact_notes']);
        $this->resetValidation();
    }

    /**
     * Spec 03 section 22: give this customer the supplier or carrier role (its supplier profile is
     * created as a prospect for purchase/logistics to qualify). Needs `counterparties.create`.
     */
    public function addSupplierRole(string $role, OrganizationRoles $roles): void
    {
        $this->authorize('create', Counterparty::class);
        abort_unless(in_array($role, ['supplier', 'carrier'], true), 404);
        $organization = Partner::query()->findOrFail($this->customerId);
        $roles->addSupplierRole($organization, ['is_supplier' => $role === 'supplier', 'is_carrier' => $role === 'carrier'], auth()->user());
        unset($this->customer);
        session()->flash('status', __('organizations.role_added', ['name' => $organization->name]));
    }

    /**
     * Product interests from the customer's leads (Spec 04 section 18.4), one row per item.
     *
     * @return \Illuminate\Support\Collection<int, LeadItem>
     */
    #[Computed]
    public function interests(): \Illuminate\Support\Collection
    {
        return auth()->user()->can('viewAny', Lead::class) || auth()->user()->can('create', Offer::class)
            ? CreateOfferFromInterests::interestsOf($this->customer)
            : collect();
    }

    /** Draft offer with the interests as lines (prices from the catalogue now), linked to the source lead. */
    public function createOfferFromInterests(CreateOfferFromInterests $action): void
    {
        $this->authorize('create', Offer::class);
        $customer = $this->customer;
        $lead = $customer->sourceLead ?? $customer->leads()->latest('id')->first();

        try {
            $result = $action->handle(auth()->user(), $customer, $customer->primaryContact, $this->interests, $lead);
        } catch (OfferActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('leads.flash.converted', ['number' => $result['offer']->displayNumber()]));

        if ($result['skipped'] !== []) {
            session()->flash('error', __('leads.flash.items_skipped', ['items' => implode('; ', $result['skipped'])]));
        }

        $this->redirectRoute('offers.edit', $result['offer'], navigate: true);
    }

    /**
     * Spec 09 section 14: service ticket updates (number and status only) in this customer's
     * linked Telegram groups; on by default.
     */
    public function toggleTelegramServiceUpdates(): void
    {
        $customer = $this->customer;
        $this->authorize('update', $customer);
        $customer->forceFill(['telegram_service_updates' => ! $customer->telegram_service_updates])->save();
        unset($this->customer, $this->activities);
        session()->flash('status', __($customer->telegram_service_updates ? 'service.telegram.customer_on' : 'service.telegram.customer_off'));
    }

    public function render(): View
    {
        return view('livewire.customers.show')->title($this->customer->name);
    }
}
