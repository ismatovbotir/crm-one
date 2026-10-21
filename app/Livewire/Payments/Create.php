<?php

namespace App\Livewire\Payments;

use App\Actions\Accounting\RecordPayment;
use App\Enums\PaymentMethod;
use App\Models\CompanyBankAccount;
use App\Models\Customer;
use App\Models\Payment;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Record a payment (Spec 06 10.2, BR-AC.5): customer, amount and currency, date, method, our bank
 * account, payer, reference, proof; duplicate warning needs an explicit confirmation. Allocation
 * follows on the payment page.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    use WithFileUploads;

    #[Url(as: 'customer', except: '')]
    public string $customerId = '';

    public string $customerSearch = '';

    public string $amount = '';

    public string $currency = 'UZS';

    public string $paidOn = '';

    public string $method = 'bank_transfer';

    public string $bankAccountId = '';

    public string $payerName = '';

    public string $payerTaxId = '';

    public string $referenceNumber = '';

    public string $purpose = '';

    public string $note = '';

    /** @var TemporaryUploadedFile|null */
    public $proof = null;

    public string $duplicateOf = '';

    public function mount(): void
    {
        $this->authorize('create', Payment::class);
        $this->paidOn = now((string) config('orders.timezone'))->toDateString();
        $this->currency = (string) config('crm.base_currency');

        if (ctype_digit($this->customerId)) {
            $this->selectCustomer((int) $this->customerId);
        }
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerOptions(): Collection
    {
        $term = trim($this->customerSearch);

        return Customer::query()
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%')))
            ->orderBy('name')
            ->limit(15)
            ->get(['id', 'name', 'tax_id', 'currency']);
    }

    /**
     * @return Collection<int, CompanyBankAccount>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return CompanyBankAccount::query()->where('is_active', true)->where('currency', $this->currency)->orderByDesc('is_default')->get();
    }

    public function selectCustomer(int $id): void
    {
        $customer = Customer::query()->find($id);

        if ($customer === null) {
            return;
        }

        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->name;
        $this->currency = $customer->currency;
        $this->payerName = $this->payerName !== '' ? $this->payerName : (string) ($customer->legal_name ?: $customer->name);
        $this->payerTaxId = $this->payerTaxId !== '' ? $this->payerTaxId : (string) $customer->tax_id;
        $this->bankAccountId = (string) (CompanyBankAccount::defaultFor($customer->currency)?->id ?? '');
        unset($this->bankAccounts);
    }

    public function updatedCurrency(): void
    {
        $this->bankAccountId = (string) (CompanyBankAccount::defaultFor($this->currency)?->id ?? '');
        unset($this->bankAccounts);
    }

    public function save(RecordPayment $record): void
    {
        $this->authorize('create', Payment::class);
        $currencies = implode(',', array_keys((array) config('crm.currencies')));
        $needsBank = PaymentMethod::tryFrom($this->method)?->needsBankDetails() ?? true;

        $this->validate([
            'customerId' => ['required', 'integer', 'exists:partners,id'],
            'amount' => ['required', 'string', 'max:30'],
            'currency' => ['required', 'in:'.$currencies],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'bankAccountId' => [$needsBank ? 'required' : 'nullable', 'integer'],
            'payerName' => ['required', 'string', 'max:255'],
            'payerTaxId' => ['nullable', 'string', 'max:32'],
            'referenceNumber' => [$needsBank ? 'required' : 'nullable', 'string', 'max:64'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'max:10240'],
        ], [], [
            'customerId' => __('accounting.fields.customer'),
            'amount' => __('accounting.fields.amount'),
            'paidOn' => __('accounting.fields.paid_on'),
            'method' => __('accounting.fields.method'),
            'bankAccountId' => __('accounting.fields.bank_account'),
            'payerName' => __('accounting.fields.payer_name'),
            'referenceNumber' => __('accounting.fields.reference_number'),
            'proof' => __('accounting.fields.proof'),
        ]);

        if (! Money::isValidMajor(trim($this->amount), $this->currency)) {
            $this->addError('amount', __('accounting.errors.amount_invalid'));

            return;
        }

        try {
            $payment = $record->handle([
                'customer_id' => (int) $this->customerId,
                'amount_minor' => Money::fromMajor(trim($this->amount), $this->currency)->amount,
                'currency' => $this->currency,
                'paid_on' => $this->paidOn,
                'method' => $this->method,
                'company_bank_account_id' => $needsBank ? (int) $this->bankAccountId : null,
                'payer_name' => $this->payerName,
                'payer_tax_id' => $this->payerTaxId ?: null,
                'reference_number' => $this->referenceNumber ?: null,
                'purpose' => $this->purpose ?: null,
                'note' => $this->note ?: null,
            ], auth()->user(), $this->proof, $this->duplicateOf !== '');
        } catch (AccountingActionException $exception) {
            if ($exception->key === 'possible_duplicate') {
                $this->duplicateOf = (string) $exception->params['number'];
            }

            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('accounting.flash.payment_recorded', ['number' => $payment->number]));
        $this->redirectRoute('payments.show', $payment, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.payments.create', [
            'methods' => PaymentMethod::options(),
            'currencies' => array_keys((array) config('crm.currencies')),
        ])->title(__('accounting.payments.new'));
    }
}
