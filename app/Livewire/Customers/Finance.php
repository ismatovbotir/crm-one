<?php

namespace App\Livewire\Customers;

use App\Actions\Agreements\GenerateAgreement;
use App\Enums\AgreementKind;
use App\Models\Agreement;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\Order;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\Orders\CreditExposure;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Customer Finance tab (Spec 06 7.9 / 10.2): agreement details (VAT code, OKED, signatory, bank
 * accounts, billing/signatory contacts), credit limit and manual hold (finance only), exposure, and
 * the customer's orders and agreements visible to the user. CRM-only data, never sent to 1C.
 */
class Finance extends Component
{
    #[Locked]
    public int $customerId;

    /** @var array{vat_registration_code: string, oked: string, signatory_name: string, signatory_position: string, signatory_basis: string} */
    public array $details = ['vat_registration_code' => '', 'oked' => '', 'signatory_name' => '', 'signatory_position' => '', 'signatory_basis' => ''];

    /** @var array{credit_limit: string, credit_limit_currency: string, manual_hold: bool, manual_hold_reason: string} */
    public array $credit = ['credit_limit' => '', 'credit_limit_currency' => '', 'manual_hold' => false, 'manual_hold_reason' => ''];

    /** @var array{bank_name: string, mfo: string, account_number: string, currency: string, is_default: bool} */
    public array $bank = ['bank_name' => '', 'mfo' => '', 'account_number' => '', 'currency' => '', 'is_default' => false];

    #[Locked]
    public ?int $bankId = null;

    public string $editing = '';

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->authorize('view', $this->customer);
        $this->fillForms();
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::withTrashed()->findOrFail($this->customerId);
    }

    /**
     * @return Collection<int, CustomerBankAccount>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return $this->customer->bankAccounts()->get();
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contacts(): Collection
    {
        return $this->customer->contacts()->orderBy('first_name')->get();
    }

    /**
     * @return array{exposure: int, currency: string|null, limit: int|null, other_currencies: list<string>}
     */
    #[Computed]
    public function exposure(): array
    {
        return app(CreditExposure::class)->for($this->customer);
    }

    /**
     * @return Collection<int, Order>
     */
    #[Computed]
    public function orders(): Collection
    {
        return Order::query()->visibleTo(auth()->user())->where('customer_id', $this->customerId)->latest('id')->limit(20)->get();
    }

    /**
     * @return Collection<int, Agreement>
     */
    #[Computed]
    public function agreements(): Collection
    {
        return Agreement::query()->visibleTo(auth()->user())->where('customer_id', $this->customerId)->latest('id')->limit(20)->get();
    }

    public function saveDetails(): void
    {
        $this->authorize('manageFinanceDetails', $this->customer);
        $data = $this->validate([
            'details.vat_registration_code' => ['nullable', 'string', 'max:20'],
            'details.oked' => ['nullable', 'string', 'max:10'],
            'details.signatory_name' => ['nullable', 'string', 'max:255'],
            'details.signatory_position' => ['nullable', 'string', 'max:255'],
            'details.signatory_basis' => ['nullable', 'string', 'max:255'],
        ], [], [
            'details.vat_registration_code' => __('customers.finance.vat_registration_code'),
            'details.oked' => __('customers.finance.oked'),
            'details.signatory_name' => __('customers.finance.signatory_name'),
        ])['details'];

        $this->customer->update(array_map(fn (string $value): ?string => trim($value) === '' ? null : trim($value), $data));
        $this->done('customers.finance.details_saved');
    }

    public function saveCredit(): void
    {
        $this->authorize('manageCredit', $this->customer);
        $currencies = implode(',', array_keys((array) config('crm.currencies')));
        $this->validate([
            'credit.credit_limit' => ['nullable', 'string', 'max:30'],
            'credit.credit_limit_currency' => ['nullable', 'required_with:credit.credit_limit', 'in:'.$currencies],
            'credit.manual_hold' => ['boolean'],
            'credit.manual_hold_reason' => ['nullable', 'required_if:credit.manual_hold,true', 'string', 'max:255'],
        ], [], [
            'credit.credit_limit' => __('customers.finance.credit_limit'),
            'credit.credit_limit_currency' => __('customers.finance.credit_limit_currency'),
            'credit.manual_hold_reason' => __('customers.finance.manual_hold_reason'),
        ]);

        $limit = null;
        $amount = trim($this->credit['credit_limit']);

        if ($amount !== '') {
            if (! Money::isValidMajor($amount, $this->credit['credit_limit_currency'])) {
                $this->addError('credit.credit_limit', __('customers.finance.credit_limit_invalid'));

                return;
            }

            $limit = Money::fromMajor($amount, $this->credit['credit_limit_currency'])->amount;
        }

        $this->customer->forceFill([
            'credit_limit_minor' => $limit,
            'credit_limit_currency' => $limit === null ? null : $this->credit['credit_limit_currency'],
            'manual_hold' => (bool) $this->credit['manual_hold'],
            'manual_hold_reason' => $this->credit['manual_hold'] ? trim($this->credit['manual_hold_reason']) : null,
        ])->save();
        $this->done('customers.finance.credit_saved');
    }

    public function toggleContactFlag(int $contactId, string $flag): void
    {
        $this->authorize('manageFinanceDetails', $this->customer);
        abort_unless(in_array($flag, ['is_billing_contact', 'is_signatory'], true), 404);
        $contact = $this->customer->contacts()->findOrFail($contactId);
        $contact->forceFill([$flag => ! $contact->{$flag}])->save();
        unset($this->contacts);
    }

    public function editBank(?int $id = null): void
    {
        $this->authorize('manageFinanceDetails', $this->customer);
        $this->resetValidation();
        $account = $id !== null ? $this->customer->bankAccounts()->findOrFail($id) : null;
        $this->bankId = $account?->id;
        $this->bank = [
            'bank_name' => (string) ($account?->bank_name ?? ''),
            'mfo' => (string) ($account?->mfo ?? ''),
            'account_number' => (string) ($account?->account_number ?? ''),
            'currency' => (string) ($account?->currency ?? $this->customer->currency),
            'is_default' => (bool) ($account?->is_default ?? $this->bankAccounts->isEmpty()),
        ];
        $this->editing = 'bank';
    }

    public function saveBank(): void
    {
        $this->authorize('manageFinanceDetails', $this->customer);
        $data = $this->validate([
            'bank.bank_name' => ['required', 'string', 'max:255'],
            'bank.mfo' => ['required', 'regex:/^\d{5}$/'],
            'bank.account_number' => ['required', 'regex:/^\d{20}$/'],
            'bank.currency' => ['required', 'in:'.implode(',', array_keys((array) config('crm.currencies')))],
            'bank.is_default' => ['boolean'],
        ], [
            'bank.mfo.regex' => __('settings.company.mfo_invalid'),
            'bank.account_number.regex' => __('settings.company.account_invalid'),
        ], [
            'bank.bank_name' => __('settings.company.fields.bank_name'),
            'bank.mfo' => __('settings.company.fields.mfo'),
            'bank.account_number' => __('settings.company.fields.account_number'),
            'bank.currency' => __('settings.company.fields.currency'),
        ])['bank'];

        DB::transaction(function () use ($data): void {
            if ($data['is_default']) {
                $this->customer->bankAccounts()->update(['is_default' => false]);
            }

            $this->customer->bankAccounts()->updateOrCreate(['id' => $this->bankId], $data);
        });

        $this->editing = '';
        $this->done('customers.finance.bank_saved');
    }

    public function removeBank(int $id): void
    {
        $this->authorize('manageFinanceDetails', $this->customer);
        $this->customer->bankAccounts()->findOrFail($id)->delete();
        $this->done('customers.finance.bank_removed');
    }

    public function generateFramework(GenerateAgreement $generate): void
    {
        $this->authorize('create', Agreement::class);

        try {
            $agreement = $generate->handle(AgreementKind::Framework, $this->customer, null, auth()->user());
        } catch (AgreementActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('agreements.flash.generated', ['number' => $agreement->number]));
        $this->redirectRoute('agreements.show', $agreement, navigate: true);
    }

    public function cancelEdit(): void
    {
        $this->editing = '';
        $this->resetValidation();
    }

    protected function fillForms(): void
    {
        $customer = $this->customer;
        $this->details = [
            'vat_registration_code' => (string) $customer->vat_registration_code,
            'oked' => (string) $customer->oked,
            'signatory_name' => (string) $customer->signatory_name,
            'signatory_position' => (string) $customer->signatory_position,
            'signatory_basis' => (string) $customer->signatory_basis,
        ];
        $this->credit = [
            'credit_limit' => $customer->credit_limit_minor !== null ? Money::of($customer->credit_limit_minor, (string) $customer->credit_limit_currency)->toMajor() : '',
            'credit_limit_currency' => (string) ($customer->credit_limit_currency ?? $customer->currency),
            'manual_hold' => (bool) $customer->manual_hold,
            'manual_hold_reason' => (string) $customer->manual_hold_reason,
        ];
    }

    protected function done(string $key): void
    {
        unset($this->customer, $this->bankAccounts, $this->exposure, $this->contacts);
        $this->fillForms();
        session()->flash('status', __($key));
    }

    public function render(): View
    {
        return view('livewire.customers.finance', [
            'currencies' => array_keys((array) config('crm.currencies')),
        ]);
    }
}
