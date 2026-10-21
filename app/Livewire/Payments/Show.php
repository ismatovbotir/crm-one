<?php

namespace App\Livewire\Payments;

use App\Actions\Accounting\AllocatePayment;
use App\Actions\Accounting\DeallocatePayment;
use App\Actions\Accounting\DecidePayment;
use App\Actions\Accounting\RecordRefund;
use App\Actions\Accounting\ResolvePayment;
use App\Actions\Accounting\VoidPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\AccountingEntry;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\FxRates;
use App\Services\Accounting\OrderPayments;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Payment detail (Spec 06 10.2): details (bank data only for authorized users), allocations with
 * reversals, allocate to orders of the same customer (FX when currencies differ), approval of
 * payments/refunds/voids by a second person, refund, unmatched/duplicate resolution, ledger.
 * Every action re-checks ids and amounts server-side.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $paymentId;

    /** allocate | reject | void | refund | link | deallocate */
    public string $panel = '';

    public string $orderId = '';

    public string $invoiceId = '';

    public string $amount = '';

    public string $fxRate = '';

    public string $reason = '';

    public string $customerSearch = '';

    #[Locked]
    public ?int $allocationId = null;

    /** @var array{amount: string, paid_on: string, method: string, reference_number: string} */
    public array $refund = ['amount' => '', 'paid_on' => '', 'method' => 'bank_transfer', 'reference_number' => ''];

    public function mount(Payment $payment): void
    {
        $this->authorize('view', $payment);
        $this->paymentId = $payment->id;
    }

    #[Computed]
    public function payment(): Payment
    {
        return Payment::query()->with(['customer', 'bankAccount', 'recorder', 'refundOf', 'duplicateOf', 'proof'])->findOrFail($this->paymentId);
    }

    /**
     * @return Collection<int, PaymentAllocation>
     */
    #[Computed]
    public function allocations(): Collection
    {
        return $this->payment->allocations()->with(['order', 'invoice', 'creator', 'reversal'])->get();
    }

    /**
     * Orders of the customer that can still take money (confirmed and later, not closed).
     *
     * @return Collection<int, Order>
     */
    #[Computed]
    public function payableOrders(): Collection
    {
        if ($this->payment->customer_id === null) {
            return new Collection;
        }

        $payments = app(OrderPayments::class);

        return Order::query()->where('customer_id', $this->payment->customer_id)
            ->whereNotIn('status', [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed])
            ->orderBy('id')->get()
            ->filter(fn (Order $order): bool => $order->gross_minor - $payments->allocatedMinor($order) > 0)
            ->values();
    }

    /**
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerOptions(): Collection
    {
        $term = trim($this->customerSearch);

        return $term === '' ? new Collection : Customer::query()->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%'))->orderBy('name')->limit(10)->get(['id', 'name', 'tax_id']);
    }

    /**
     * @return Collection<int, AccountingEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return AccountingEntry::query()->with('causer')->where('payment_id', $this->paymentId)->orderBy('id')->get();
    }

    public function openPanel(string $panel, ?int $allocationId = null): void
    {
        $this->resetValidation();
        $this->reset(['orderId', 'invoiceId', 'amount', 'fxRate', 'reason', 'customerSearch']);
        $this->panel = in_array($panel, ['allocate', 'reject', 'void', 'refund', 'link', 'deallocate'], true) ? $panel : '';
        $this->allocationId = $allocationId;

        if ($panel === 'refund') {
            $this->refund = ['amount' => Money::of($this->payment->unallocatedMinor(), $this->payment->currency)->toMajor(), 'paid_on' => now((string) config('orders.timezone'))->toDateString(), 'method' => $this->payment->method->value, 'reference_number' => ''];
        }
    }

    public function closePanel(): void
    {
        $this->panel = '';
        $this->resetValidation();
    }

    public function updatedOrderId(): void
    {
        $order = $this->payableOrders->firstWhere('id', (int) $this->orderId);

        if ($order === null) {
            return;
        }

        $payment = $this->payment;
        $remaining = $order->gross_minor - app(OrderPayments::class)->allocatedMinor($order);

        if ($payment->currency === $order->currency) {
            $this->fxRate = '';
            $this->amount = Money::of(min($remaining, $payment->unallocatedMinor()), $payment->currency)->toMajor();
        } else {
            $this->fxRate = (string) (app(FxRates::class)->propose($payment->currency, $order->currency, $payment->paid_on) ?? '');
            $this->amount = Money::of($payment->unallocatedMinor(), $payment->currency)->toMajor();
        }
    }

    public function allocate(AllocatePayment $allocate): void
    {
        $this->authorize('allocate', $this->payment);
        $this->validate([
            'orderId' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:30'],
            'fxRate' => ['nullable', 'numeric', 'gt:0'],
            'invoiceId' => ['nullable', 'integer'],
        ], [], ['orderId' => __('accounting.fields.order'), 'amount' => __('accounting.fields.amount'), 'fxRate' => __('accounting.fields.fx_rate')]);

        $payment = $this->payment;

        if (! Money::isValidMajor(trim($this->amount), $payment->currency)) {
            $this->addError('amount', __('accounting.errors.amount_invalid'));

            return;
        }

        $order = Order::query()->findOrFail((int) $this->orderId);
        $invoice = $this->invoiceId !== '' ? Invoice::query()->find((int) $this->invoiceId) : null;
        $this->run(fn () => $allocate->handle($payment, $order, Money::fromMajor(trim($this->amount), $payment->currency)->amount, auth()->user(), $invoice, $this->fxRate !== '' ? $this->fxRate : null), 'accounting.flash.allocated');
    }

    public function deallocate(DeallocatePayment $deallocate): void
    {
        $this->authorize('allocate', $this->payment);
        $this->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('accounting.fields.reason')]);
        $allocation = PaymentAllocation::query()->where('payment_id', $this->paymentId)->findOrFail((int) $this->allocationId);
        $this->run(fn () => $deallocate->handle($allocation, auth()->user(), trim($this->reason)), 'accounting.flash.deallocated');
    }

    public function approve(DecidePayment $decide): void
    {
        $this->authorize('approve', $this->payment);
        $this->run(fn () => $decide->approve($this->payment, auth()->user()), 'accounting.flash.payment_approved');
    }

    public function reject(DecidePayment $decide): void
    {
        $this->authorize('approve', $this->payment);
        $this->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('accounting.fields.reason')]);
        $this->run(fn () => $decide->reject($this->payment, auth()->user(), trim($this->reason)), 'accounting.flash.payment_rejected');
    }

    public function requestVoid(VoidPayment $void): void
    {
        $this->authorize('requestVoid', $this->payment);
        $this->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('accounting.fields.reason')]);
        $this->run(fn () => $void->request($this->payment, auth()->user(), trim($this->reason)), 'accounting.flash.void_requested');
    }

    public function approveVoid(VoidPayment $void): void
    {
        $this->authorize('approveVoid', $this->payment);
        $this->run(fn () => $void->approve($this->payment, auth()->user()), 'accounting.flash.voided');
    }

    public function saveRefund(RecordRefund $record): void
    {
        $this->authorize('refund', $this->payment);
        $this->validate([
            'refund.amount' => ['required', 'string', 'max:30'],
            'refund.paid_on' => ['required', 'date'],
            'refund.method' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'refund.reference_number' => ['nullable', 'string', 'max:64'],
        ], [], ['refund.amount' => __('accounting.fields.amount'), 'refund.paid_on' => __('accounting.fields.paid_on')]);

        if (! Money::isValidMajor(trim($this->refund['amount']), $this->payment->currency)) {
            $this->addError('refund.amount', __('accounting.errors.amount_invalid'));

            return;
        }

        try {
            $refund = $record->handle($this->payment, [
                'amount_minor' => Money::fromMajor(trim($this->refund['amount']), $this->payment->currency)->amount,
                'paid_on' => $this->refund['paid_on'],
                'method' => $this->refund['method'],
                'reference_number' => $this->refund['reference_number'] ?: null,
            ], auth()->user());
        } catch (AccountingActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('accounting.flash.refund_recorded', ['number' => $refund->number]));
        $this->redirectRoute('payments.show', $refund, navigate: true);
    }

    public function linkCustomer(ResolvePayment $resolve, int $customerId): void
    {
        $this->authorize('resolve', $this->payment);
        $customer = Customer::query()->findOrFail($customerId);
        $this->run(fn () => $resolve->linkCustomer($this->payment, $customer, auth()->user()), 'accounting.flash.customer_linked');
    }

    public function markSame(ResolvePayment $resolve): void
    {
        $this->authorize('resolve', $this->payment);

        try {
            $original = $resolve->markSame($this->payment, auth()->user());
        } catch (AccountingActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('accounting.flash.duplicate_merged'));
        $this->redirectRoute('payments.show', $original, navigate: true);
    }

    public function markDistinct(ResolvePayment $resolve): void
    {
        $this->authorize('resolve', $this->payment);
        $this->run(fn () => $resolve->markDistinct($this->payment, auth()->user()), 'accounting.flash.duplicate_distinct');
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (AccountingActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refresh();

            return;
        }

        session()->flash('status', __($successKey));
        $this->panel = '';
        $this->refresh();
    }

    protected function refresh(): void
    {
        unset($this->payment, $this->allocations, $this->payableOrders, $this->entries);
    }

    public function render(): View
    {
        return view('livewire.payments.show', [
            'methods' => PaymentMethod::options(),
        ])->title($this->payment->number);
    }
}
