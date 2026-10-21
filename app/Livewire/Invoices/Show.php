<?php

namespace App\Livewire\Invoices;

use App\Actions\Accounting\VoidInvoice;
use App\Enums\EsfStatus;
use App\Models\Invoice;
use App\Services\Accounting\Exceptions\AccountingActionException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Invoice detail (Spec 06 10.2): lines with codes and warnings, print, ESF reference (tax
 * documents), void request and approval by a second person.
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $invoiceId;

    public string $panel = '';

    public string $reason = '';

    public string $esfId = '';

    public string $esfStatus = '';

    public function mount(Invoice $invoice): void
    {
        $this->authorize('view', $invoice);
        $this->invoiceId = $invoice->id;
        $this->esfId = (string) $invoice->esf_id;
        $this->esfStatus = (string) $invoice->esf_status?->value;
    }

    #[Computed]
    public function invoice(): Invoice
    {
        return Invoice::query()->with(['lines', 'order', 'customer', 'bankAccount', 'issuer'])->findOrFail($this->invoiceId);
    }

    public function requestVoid(VoidInvoice $void): void
    {
        $this->authorize('requestVoid', $this->invoice);
        $this->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => __('accounting.fields.reason')]);
        $this->run(fn () => $void->request($this->invoice, auth()->user(), trim($this->reason)), 'accounting.flash.void_requested');
    }

    public function approveVoid(VoidInvoice $void): void
    {
        $this->authorize('approveVoid', $this->invoice);
        $this->run(fn () => $void->approve($this->invoice, auth()->user()), 'accounting.flash.invoice_voided');
    }

    public function saveEsf(): void
    {
        $this->authorize('updateEsf', $this->invoice);
        $this->validate([
            'esfId' => ['nullable', 'string', 'max:64'],
            'esfStatus' => ['nullable', 'in:'.implode(',', array_column(EsfStatus::cases(), 'value'))],
        ], [], ['esfId' => __('accounting.fields.esf_id'), 'esfStatus' => __('accounting.fields.esf_status')]);

        $this->invoice->update(['esf_id' => $this->esfId ?: null, 'esf_status' => $this->esfStatus ?: null]);
        unset($this->invoice);
        session()->flash('status', __('accounting.flash.esf_saved'));
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (AccountingActionException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->invoice);

            return;
        }

        session()->flash('status', __($successKey));
        $this->panel = '';
        unset($this->invoice);
    }

    public function render(): View
    {
        return view('livewire.invoices.show', ['esfStatuses' => EsfStatus::options()])->title($this->invoice->number);
    }
}
