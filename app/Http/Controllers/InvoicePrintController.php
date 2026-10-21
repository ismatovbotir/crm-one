<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Enums\InvoiceType;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Support\BusinessSettings;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Printed invoice (Spec 06 section 12): proforma ("This is not a VAT invoice", our bank account,
 * amount due, pay-by date) or a reference copy of a registered tax document. Code columns follow
 * `accounting.invoice_print_columns`.
 */
class InvoicePrintController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $invoice);

        return $this->render($request, $invoice, $renderer, route('invoices.show', $invoice));
    }

    /** The printed invoice without authorization (callers authorize: staff policy or portal scope). */
    public function render(Request $request, Invoice $invoice, DocumentRenderer $renderer, string $backUrl): View
    {
        $invoice->load(['lines', 'order', 'customer', 'bankAccount']);
        $language = DocumentLanguage::resolve($request->query('lang'), $invoice);

        // Spec 06 section 25: the retail sales receipt has its own layout with the payments received.
        if ($invoice->type === InvoiceType::SalesReceipt) {
            $invoice->loadMissing('issuer');

            return $renderer->print('documents.sales_receipt', [
                'invoice' => $invoice,
                'seller' => CompanyProfile::seller($language),
                'payments' => $this->receivedPayments($invoice),
            ], $language, __('documents.sales_receipt.title', [], $language->value).' '.$invoice->number, $backUrl);
        }

        return $renderer->print('documents.invoice', [
            'invoice' => $invoice,
            'seller' => CompanyProfile::seller($language),
            'columns' => array_merge(['accountant_code' => false, 'class_code' => true, 'package_code' => true], BusinessSettings::array('accounting.invoice_print_columns')),
            'director' => CompanyProfile::get('director'),
            'chiefAccountant' => CompanyProfile::get('chief_accountant'),
        ], $language, __('documents.invoice.types.'.$invoice->type->value, [], $language->value).' '.$invoice->number, $backUrl);
    }

    /**
     * Payments allocated to the receipt's order (net of reversals), oldest first.
     *
     * @return Collection<int, array{number: string, method: PaymentMethod|null, paid_on: mixed, amount: int}>
     */
    protected function receivedPayments(Invoice $invoice): Collection
    {
        return PaymentAllocation::query()->with('payment')->where('order_id', $invoice->order_id)->get()
            ->groupBy('payment_id')
            ->map(fn (Collection $rows): array => [
                'number' => (string) $rows->first()->payment?->number,
                'method' => $rows->first()->payment?->method,
                'paid_on' => $rows->first()->payment?->paid_on,
                'amount' => (int) $rows->sum('order_amount_minor'),
            ])
            ->filter(fn (array $row): bool => $row['amount'] > 0)
            ->sortBy(fn (array $row): string => (string) $row['paid_on'])
            ->values();
    }
}
