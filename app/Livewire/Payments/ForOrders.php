<?php

namespace App\Livewire\Payments;

use App\Actions\Accounting\RecordOrderPayments;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\OrderPayments;
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
 * "Payment for orders" (Spec 06 section 29): partner → its unpaid / partly paid orders with
 * checkboxes → amount per order (default outstanding) → date and method. One payment per ticked
 * order, allocated at once. An information signal for the warehouse; the books stay in 1C.
 */
#[Layout('layouts.app')]
class ForOrders extends Component
{
    use WithFileUploads;

    #[Url(as: 'customer', except: '')]
    public string $customerId = '';

    public string $customerSearch = '';

    /** @var array<int, bool> order id => ticked */
    public array $selected = [];

    /** @var array<int, string> order id => amount (major units, order currency) */
    public array $amounts = [];

    public string $paidOn = '';

    public string $method = 'bank_transfer';

    public string $referenceNumber = '';

    public string $note = '';

    /** @var TemporaryUploadedFile|null */
    public $proof = null;

    public bool $confirmDuplicate = false;

    public function mount(): void
    {
        $this->authorize('create', Payment::class);
        $this->paidOn = now((string) config('orders.timezone'))->toDateString();

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

        return Customer::query()->visibleTo(auth()->user())
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%')))
            ->orderBy('name')->limit(15)->get(['id', 'name', 'tax_id']);
    }

    /**
     * The partner's orders that still have something to pay, oldest first.
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function orders(): Collection
    {
        if ($this->customerId === '') {
            return new Collection;
        }

        if (! Customer::query()->visibleTo(auth()->user())->whereKey((int) $this->customerId)->exists()) {
            return new Collection;
        }

        return Order::query()->where('customer_id', (int) $this->customerId)
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed])
            ->whereIn('payment_status', [OrderPaymentStatus::Unpaid, OrderPaymentStatus::PartiallyPaid, OrderPaymentStatus::Overdue])
            ->orderBy('confirmed_at')->orderBy('id')->get();
    }

    /** Outstanding (minor) of an order. */
    public function outstanding(Order $order): int
    {
        return max(0, $order->gross_minor - app(OrderPayments::class)->allocatedMinor($order));
    }

    public function selectCustomer(int $id): void
    {
        // Prefill (?customer=) and picks are limited to customers the user may view; others are ignored.
        $customer = Customer::query()->visibleTo(auth()->user())->find($id);

        if ($customer === null) {
            $this->customerId = '';

            return;
        }

        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->name;
        $this->selected = [];
        $this->amounts = [];
        unset($this->orders);
    }

    public function clearCustomer(): void
    {
        $this->reset('customerId', 'customerSearch', 'selected', 'amounts');
        unset($this->orders);
    }

    /** Ticking an order fills its amount with the outstanding amount (editable). */
    public function updatedSelected(mixed $value, string $orderId): void
    {
        $order = $this->orders->firstWhere('id', (int) $orderId);

        if ($order !== null && $value) {
            $this->amounts[(int) $orderId] = Money::of($this->outstanding($order), $order->currency)->toMajor();
        }
    }

    /**
     * Total of the ticked orders per currency (minor).
     *
     * @return array<string, int>
     */
    #[Computed]
    public function totals(): array
    {
        $totals = [];

        foreach ($this->orders as $order) {
            if (! ($this->selected[$order->id] ?? false)) {
                continue;
            }

            $value = trim((string) ($this->amounts[$order->id] ?? ''));

            if ($value !== '' && Money::isValidMajor($value, $order->currency)) {
                $totals[$order->currency] = ($totals[$order->currency] ?? 0) + Money::fromMajor($value, $order->currency)->amount;
            }
        }

        return $totals;
    }

    public function save(RecordOrderPayments $record): void
    {
        $this->authorize('create', Payment::class);
        $needsReference = PaymentMethod::tryFrom($this->method)?->needsBankDetails() ?? true;
        $this->validate([
            'customerId' => ['required', 'integer', 'exists:partners,id'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'referenceNumber' => [$needsReference ? 'required' : 'nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
            'proof' => ['nullable', 'file', 'max:10240'],
        ], [], [
            'customerId' => __('accounting.fields.customer'),
            'paidOn' => __('accounting.fields.paid_on'),
            'method' => __('accounting.fields.method'),
            'referenceNumber' => __('accounting.fields.reference_number'),
            'proof' => __('accounting.fields.proof'),
        ]);

        $amounts = [];

        foreach ($this->orders as $order) {
            if (! ($this->selected[$order->id] ?? false)) {
                continue;
            }

            $value = trim((string) ($this->amounts[$order->id] ?? ''));

            if (! Money::isValidMajor($value, $order->currency) || Money::fromMajor($value, $order->currency)->amount <= 0) {
                $this->addError('amounts.'.$order->id, __('accounting.errors.amount_invalid'));

                return;
            }

            $amounts[$order->id] = Money::fromMajor($value, $order->currency)->amount;
        }

        try {
            $payments = $record->handle([
                'customer_id' => (int) $this->customerId,
                'paid_on' => $this->paidOn,
                'method' => $this->method,
                'reference_number' => $this->referenceNumber ?: null,
                'note' => $this->note ?: null,
                'orders' => $amounts,
            ], auth()->user(), $this->proof, $this->confirmDuplicate);
        } catch (AccountingActionException $exception) {
            if ($exception->key === 'possible_duplicate') {
                $this->confirmDuplicate = true;
            }

            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', trans_choice('accounting.order_payments.recorded', count($payments), ['count' => count($payments)]));
        $this->redirectRoute('payments.index', navigate: true);
    }

    public function render(): View
    {
        return view('livewire.payments.for-orders', [
            'methods' => PaymentMethod::options(),
        ])->title(__('accounting.order_payments.title'));
    }
}
