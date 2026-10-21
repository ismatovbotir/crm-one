<?php

namespace App\Livewire\Offers;

use App\Actions\Offers\CreateOffer;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Offer;
use App\Services\Offers\Exceptions\OfferActionException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Start a draft offer (Spec 05 US-1): customer, contact, currency, validity. Leads come with
 * Spec 04 (the offer keeps a nullable lead_id).
 */
#[Layout('layouts.app')]
class Create extends Component
{
    public string $customerSearch = '';

    #[Url(as: 'customer', except: null)]
    public ?int $customerId = null;

    public ?int $contactId = null;

    public string $currency = '';

    public string $validUntil = '';

    public function mount(): void
    {
        $this->authorize('create', Offer::class);

        $this->validUntil = CarbonImmutable::now((string) config('offers.timezone'))->addDays((int) config('offers.default_validity_days', 14))->toDateString();

        if ($this->customerId !== null) {
            $this->selectCustomer($this->customerId);
        }
    }

    /**
     * Customers the user may see and quote: active, not deleted in 1C.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customers(): Collection
    {
        $term = trim($this->customerSearch);

        return Customer::query()
            ->visibleTo(auth()->user())
            ->notDeletedInOneC()
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('legal_name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%')))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'tax_id', 'currency', 'city']);
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

    public function selectCustomer(int $customerId): void
    {
        $customer = Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()->find($customerId);

        if ($customer === null) {
            $this->customerId = null;

            return;
        }

        $this->customerId = $customer->id;
        $this->currency = $customer->currency;
        $this->contactId = $customer->primaryContact?->id;
        unset($this->customer, $this->contacts);
    }

    public function save(CreateOffer $create): void
    {
        $this->authorize('create', Offer::class);

        $max = CarbonImmutable::now((string) config('offers.timezone'))->addDays((int) config('offers.max_validity_days', 90))->toDateString();
        $today = CarbonImmutable::now((string) config('offers.timezone'))->toDateString();

        $this->validate([
            'customerId' => ['required', 'integer'],
            'contactId' => ['nullable', 'integer', Rule::exists(Contact::class, 'id')->where('partner_id', $this->customerId)->whereNull('deleted_at')],
            'currency' => ['required', Rule::in(array_keys((array) config('crm.currencies')))],
            'validUntil' => ['required', 'date', 'after_or_equal:'.$today, 'before_or_equal:'.$max],
        ], [], [
            'customerId' => __('offers.fields.customer'),
            'contactId' => __('offers.fields.contact'),
            'currency' => __('offers.fields.currency'),
            'validUntil' => __('offers.fields.valid_until'),
        ]);

        $customer = $this->customer;

        if ($customer === null) {
            $this->addError('customerId', __('offers.errors.customer_unavailable'));

            return;
        }

        $this->authorize('view', $customer);

        try {
            $offer = $create->handle(auth()->user(), $customer, $this->contactId ? Contact::query()->find($this->contactId) : null, [
                'currency' => $this->currency,
                'valid_until' => $this->validUntil,
            ]);
        } catch (OfferActionException $exception) {
            $this->addError('customerId', $exception->getMessage());

            return;
        }

        session()->flash('status', __('offers.flash.created', ['number' => $offer->displayNumber()]));
        $this->redirectRoute('offers.edit', $offer, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.offers.create', [
            'currencies' => array_keys((array) config('crm.currencies')),
        ])->title(__('offers.new'));
    }
}
