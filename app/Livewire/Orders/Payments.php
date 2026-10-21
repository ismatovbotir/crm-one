<?php

namespace App\Livewire\Orders;

use App\Actions\Accounting\CloseOrder;
use App\Actions\Accounting\IssueInvoice;
use App\Actions\Accounting\UpdatePaymentTerms;
use App\Enums\InvoiceType;
use App\Models\Order;
use App\Models\PaymentAllocation;
use App\Models\PaymentScheduleItem;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\InvoiceCodeValidator;
use App\Services\Accounting\OrderPayments;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Order "Payments & invoices" tab (Spec 06 10.2): schedule with FIFO coverage and overdue days,
 * allocations, invoices; actions per policy: payment terms, issue proforma (code check), register
 * a tax document (qty ≤ delivered − invoiced), close the order.
 */
class Payments extends Component
{
    #[Locked]
    public int $orderId;

    /** terms | proforma | tax */
    public string $panel = '';

    public string $prepaymentPercent = '';

    public string $paymentDays = '';

    public string $requestedAmount = '';

    public bool $acknowledgeCodes = false;

    /** @var array{type: string, number: string, issued_on: string, esf_id: string} */
    public array $tax = ['type' => 'tax_invoice', 'number' => '', 'issued_on' => '', 'esf_id' => ''];

    /** @var array<int, string> order line id => quantity */
    public array $taxLines = [];

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->authorize('viewPayments', $this->order);
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['lines.item', 'customer'])->findOrFail($this->orderId);
    }

    /**
     * @return list<array{item: PaymentScheduleItem, covered: int, outstanding: int, days_overdue: int|null}>
     */
    #[Computed]
    public function coverage(): array
    {
        return app(OrderPayments::class)->coverage($this->order);
    }

    /**
     * @return Collection<int, PaymentAllocation>
     */
    #[Computed]
    public function allocations(): Collection
    {
        return $this->order->allocations()->with(['payment', 'creator'])->get();
    }

    /**
     * @return list<array{line: int, name: string, code: string, mode: string}>
     */
    #[Computed]
    public function codeProblems(): array
    {
        return app(InvoiceCodeValidator::class)->check($this->order->lines);
    }

    public function openPanel(string $panel): void
    {
        $this->resetValidation();
        $this->reset(['acknowledgeCodes', 'requestedAmount']);
        $this->panel = in_array($panel, ['terms', 'proforma', 'tax'], true) ? $panel : '';
        $order = $this->order;

        if ($panel === 'terms') {
            $this->prepaymentPercent = $order->prepayment_bp !== null ? rtrim(rtrim(bcdiv((string) $order->prepayment_bp, '100', 2), '0'), '.') : '';
            $this->paymentDays = (string) ($order->payment_terms_days ?? '');
        }

        if ($panel === 'tax') {
            $this->tax = ['type' => 'tax_invoice', 'number' => '', 'issued_on' => now((string) config('orders.timezone'))->toDateString(), 'esf_id' => ''];
            $this->taxLines = $order->lines->mapWithKeys(fn ($line): array => [$line->id => Quantity::trimmed(bcsub((string) $line->delivered_qty, (string) $line->invoiced_qty, Quantity::SCALE))])->all();
        }
    }

    public function closePanel(): void
    {
        $this->panel = '';
        $this->resetValidation();
    }

    public function saveTerms(UpdatePaymentTerms $update): void
    {
        $this->authorize('amendSchedule', $this->order);
        $this->validate([
            'prepaymentPercent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'paymentDays' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [], ['prepaymentPercent' => __('accounting.fields.prepayment_percent'), 'paymentDays' => __('accounting.fields.payment_days')]);

        $bp = $this->prepaymentPercent === '' ? null : (int) bcmul($this->prepaymentPercent, '100', 0);
        $this->run(fn () => $update->handle($this->order, auth()->user(), $bp, $this->paymentDays === '' ? null : (int) $this->paymentDays), 'accounting.flash.terms_saved');
    }

    public function issueProforma(IssueInvoice $issue): void
    {
        $this->authorize('issueProforma', $this->order);
        $this->validate(['requestedAmount' => ['nullable', 'string', 'max:30']], [], ['requestedAmount' => __('accounting.fields.requested_amount')]);
        $amount = null;

        if (trim($this->requestedAmount) !== '') {
            if (! Money::isValidMajor(trim($this->requestedAmount), $this->order->currency)) {
                $this->addError('requestedAmount', __('accounting.errors.amount_invalid'));

                return;
            }

            $amount = Money::fromMajor(trim($this->requestedAmount), $this->order->currency)->amount;
        }

        $this->run(fn () => $issue->proforma($this->order, auth()->user(), ['requested_amount_minor' => $amount], $this->acknowledgeCodes), 'accounting.flash.proforma_issued');
    }

    /** Retail payment document (Spec 06 section 25): sales receipt for a fully paid order. */
    public function issueReceipt(IssueInvoice $issue): void
    {
        $this->authorize('issueReceipt', $this->order);
        $this->run(fn () => $issue->salesReceipt($this->order, auth()->user()), 'accounting.flash.receipt_issued');
        $this->dispatch('order-updated');
    }

    public function registerTax(IssueInvoice $issue): void
    {
        $this->authorize('registerTaxDocument', $this->order);
        $this->validate([
            'tax.type' => ['required', 'in:'.implode(',', array_map(fn (InvoiceType $type): string => $type->value, InvoiceType::taxDocuments()))],
            'tax.number' => ['required', 'string', 'max:40'],
            'tax.issued_on' => ['required', 'date'],
            'tax.esf_id' => ['nullable', 'string', 'max:64'],
            'taxLines.*' => ['nullable', 'string', 'max:20'],
        ], [], ['tax.type' => __('accounting.fields.document_type'), 'tax.number' => __('accounting.fields.document_number'), 'tax.issued_on' => __('accounting.fields.document_date'), 'tax.esf_id' => __('accounting.fields.esf_id')]);

        $this->run(fn () => $issue->registerTaxDocument($this->order, auth()->user(), [...$this->tax, 'esf_id' => $this->tax['esf_id'] ?: null, 'lines' => $this->taxLines], $this->acknowledgeCodes), 'accounting.flash.tax_registered');
    }

    public function closeOrder(CloseOrder $close): void
    {
        $this->authorize('close', $this->order);
        $this->run(fn () => $close->handle($this->order, auth()->user()), 'accounting.flash.order_closed');
        $this->dispatch('order-updated');
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
        unset($this->order, $this->coverage, $this->allocations, $this->codeProblems);
    }

    public function render(): View
    {
        return view('livewire.orders.payments', [
            'taxTypes' => InvoiceType::taxDocuments(),
        ]);
    }
}
