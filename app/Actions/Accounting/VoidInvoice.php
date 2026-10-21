<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\Ledger;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Invoice void (Spec 06 6.6, SoD-3): requested with a reason by the accountant, approved by
 * finance (not the requester). Tax documents give their quantities back (`invoiced_qty`);
 * allocations that referenced the invoice keep the order (the invoice link only informs).
 */
class VoidInvoice
{
    public function __construct(protected AccountingPeriods $periods, protected Ledger $ledger, protected OneCOutbox $outbox) {}

    public function request(Invoice $invoice, User $user, string $reason): Invoice
    {
        $invoice = $invoice->fresh();

        if ($invoice->status !== InvoiceStatus::Issued || $invoice->void_requested_at !== null) {
            throw new AccountingActionException('invoice_not_voidable');
        }

        $invoice->forceFill(['void_requested_at' => now(), 'void_requested_by' => $user->id, 'void_reason' => $reason])->save();

        return $invoice;
    }

    public function approve(Invoice $invoice, User $approver): Invoice
    {
        return DB::transaction(function () use ($invoice, $approver): Invoice {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->status !== InvoiceStatus::Issued || $invoice->void_requested_at === null) {
                throw new AccountingActionException('invoice_not_voidable');
            }

            if ($invoice->void_requested_by === $approver->id) {
                throw new AccountingActionException('self_approval');
            }

            $this->periods->assertOpen(AccountingPeriods::today());
            $invoice->forceFill(['status' => InvoiceStatus::Voided, 'voided_at' => now(), 'void_approved_by' => $approver->id])->save();

            if ($invoice->type->isTaxDocument()) {
                foreach ($invoice->lines as $line) {
                    $orderLine = $line->orderLine()->lockForUpdate()->first();
                    $orderLine?->forceFill(['invoiced_qty' => bcsub((string) $orderLine->invoiced_qty, (string) $line->quantity, Quantity::SCALE)])->save();
                }

                $order = $invoice->order;
                $order->forceFill(['invoiced_gross_minor' => max(0, $order->invoiced_gross_minor - $invoice->gross_minor)])->save();
                $this->ledger->write(EntryType::InvoiceVoided, -$invoice->gross_minor, $invoice->currency, AccountingPeriods::today(), ['customer_id' => $invoice->customer_id, 'order_id' => $invoice->order_id, 'invoice_id' => $invoice->id], $approver, 'crm', ['reason' => $invoice->void_reason]);
            }

            $this->outbox->publishInvoice('invoice.voided', $invoice);

            return $invoice;
        });
    }

    /** Allocations pointing at the invoice (shown on the void panel). */
    public function allocationCount(Invoice $invoice): int
    {
        return PaymentAllocation::query()->where('invoice_id', $invoice->id)->count();
    }
}
