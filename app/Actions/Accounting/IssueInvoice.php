<?php

namespace App\Actions\Accounting;

use App\Enums\EntryType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\CompanyBankAccount;
use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use App\Services\Accounting\InvoiceCodeValidator;
use App\Services\Accounting\Ledger;
use App\Services\Accounting\OrderPayments;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\OfferCalculator;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Invoices of an order (Spec 06 DEC-6, BR-AC.3/4/12):
 *  - proforma (`PF-`): all order lines with their amounts, requested amount = next uncovered
 *    schedule item by default, our default bank account in the order currency; outbox
 *    `invoice.issued`;
 *  - tax document (VAT invoice, act, waybill) registered from 1C / e-invoicing: external number
 *    and date, lines with qty ≤ delivered − invoiced, amounts from the order line price (same line
 *    math, half up), moves `invoiced_qty`, order → invoiced when everything delivered is invoiced;
 *    outbox `invoice.registered`.
 * Code validation runs on both (warn needs the acknowledgement, block refuses). Open period only.
 */
class IssueInvoice
{
    public function __construct(
        protected AccountingPeriods $periods,
        protected InvoiceCodeValidator $codes,
        protected Ledger $ledger,
        protected OneCOutbox $outbox,
        protected OfferCalculator $calculator,
        protected OrderPayments $orderPayments,
    ) {}

    /**
     * @param  array{requested_amount_minor?: int|null, due_on?: string|null, bank_account_id?: int|null}  $data
     */
    public function proforma(Order $order, User $user, array $data = [], bool $acknowledgeCodes = false): Invoice
    {
        $order = $order->fresh(['lines.item']);

        if (in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed], true)) {
            throw new AccountingActionException('order_not_invoiceable');
        }

        $today = AccountingPeriods::today();
        $this->periods->assertOpen($today);
        $warnings = $this->validateCodes($order->lines, $acknowledgeCodes);

        $next = collect($this->orderPayments->coverage($order))->first(fn (array $row): bool => $row['outstanding'] > 0);
        $requested = $data['requested_amount_minor'] ?? ($next['outstanding'] ?? max(0, $order->gross_minor - $order->paid_minor));

        if ($requested <= 0 || $requested > $order->gross_minor) {
            throw new AccountingActionException('requested_amount_invalid');
        }

        $bank = isset($data['bank_account_id'])
            ? CompanyBankAccount::query()->whereKey($data['bank_account_id'])->where('is_active', true)->where('currency', $order->currency)->first()
            : CompanyBankAccount::defaultFor($order->currency);

        if ($bank === null) {
            throw new AccountingActionException('bank_account_missing', ['currency' => $order->currency]);
        }

        return DB::transaction(function () use ($order, $user, $data, $warnings, $next, $requested, $bank, $today): Invoice {
            $year = (int) $today->year;
            $invoice = $this->create($order, $user, InvoiceType::Proforma, NumberSequence::format('PF', $year, NumberSequence::next('PF', $year)), $today->toDateString(), [
                'source' => 'crm',
                'due_on' => $data['due_on'] ?? ($next !== null ? $next['item']->due_on?->toDateString() : null),
                'requested_amount_minor' => $requested,
                'bank_account_id' => $bank->id,
                'code_warnings' => $warnings === [] ? null : $warnings,
            ], $order->lines->map(fn (OrderLine $line): array => [$line, (string) $line->quantity, $line->line_net_minor, $line->line_vat_minor, $line->line_gross_minor])->all());

            $this->outbox->publishInvoice('invoice.issued', $invoice);

            return $invoice;
        });
    }

    /**
     * Retail payment document (Spec 06 section 25, DEC-25.4): sales receipt `SR-` for a retail order
     * that is fully paid; all order lines with their amounts; one issued receipt per order (void and
     * re-issue to correct). Not a tax document: no ledger entry (the payment is already booked) and
     * `invoiced_qty` is not moved. Added to the 1C outbox as `invoice.issued`, available for 1C to
     * pull. Open period only.
     */
    public function salesReceipt(Order $order, User $user): Invoice
    {
        $today = AccountingPeriods::today();
        $this->periods->assertOpen($today);

        return DB::transaction(function () use ($order, $user, $today): Invoice {
            $order = Order::query()->with('lines.item')->lockForUpdate()->findOrFail($order->id);

            if (! $order->sale_type->isRetail()) {
                throw new AccountingActionException('receipt_retail_only');
            }

            if (in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled], true)) {
                throw new AccountingActionException('order_not_invoiceable');
            }

            if ($order->payment_status !== OrderPaymentStatus::Paid) {
                throw new AccountingActionException('receipt_not_paid');
            }

            if ($order->hasIssuedReceipt()) {
                throw new AccountingActionException('receipt_exists');
            }

            $year = (int) $today->year;
            $invoice = $this->create($order, $user, InvoiceType::SalesReceipt, NumberSequence::format('SR', $year, NumberSequence::next('SR', $year)), $today->toDateString(), [
                'source' => 'crm',
                'requested_amount_minor' => $order->gross_minor,
            ], $order->lines->map(fn (OrderLine $line): array => [$line, (string) $line->quantity, $line->line_net_minor, $line->line_vat_minor, $line->line_gross_minor])->all());

            $this->outbox->publishInvoice('invoice.issued', $invoice);

            return $invoice;
        });
    }

    /**
     * @param  array{type: string, number: string, issued_on: string, esf_id?: string|null, lines: array<int, string>}  $data  lines: order_line_id => quantity
     */
    public function registerTaxDocument(Order $order, User $user, array $data, bool $acknowledgeCodes = false): Invoice
    {
        $type = InvoiceType::from($data['type']);

        if (! $type->isTaxDocument()) {
            throw new AccountingActionException('not_tax_document');
        }

        $this->periods->assertOpen($data['issued_on']);

        return DB::transaction(function () use ($order, $user, $data, $type, $acknowledgeCodes): Invoice {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            // Spec 06 section 28: the tax invoice / waybill waits for the power of attorney.
            if (in_array($type, [InvoiceType::TaxInvoice, InvoiceType::Waybill], true) && ! $order->poaSatisfied()) {
                throw new AccountingActionException('poa_missing');
            }

            $lines = [];

            foreach ($data['lines'] as $lineId => $quantity) {
                $quantity = Quantity::normalize((string) $quantity);

                if ($quantity === '' || bccomp($quantity, '0', Quantity::SCALE) === 0) {
                    continue;
                }

                $line = $order->lines()->with('item')->lockForUpdate()->whereKey((int) $lineId)->first();

                if ($line === null || ! Quantity::isDecimal($quantity)) {
                    throw new AccountingActionException('invoice_line_invalid');
                }

                $open = bcsub((string) $line->delivered_qty, (string) $line->invoiced_qty, Quantity::SCALE);

                if (bccomp($quantity, $open, Quantity::SCALE) > 0) {
                    throw new AccountingActionException('invoice_qty_exceeds', ['name' => $line->name, 'qty' => Quantity::trimmed($open)]);
                }

                $amounts = $this->calculator->line(new CalculatorLine($line->id, $quantity, $line->unit_price_minor, $line->discount_bp, $line->tax_rate_bp), $order->prices_include_vat);
                $lines[] = [$line, $quantity, $amounts->net, $amounts->vat, $amounts->gross];
            }

            if ($lines === []) {
                throw new AccountingActionException('invoice_no_lines');
            }

            $warnings = $this->validateCodes(array_column($lines, 0), $acknowledgeCodes);

            if (Invoice::query()->where('type', $type)->where('number', trim($data['number']))->whereDate('issued_on', $data['issued_on'])->exists()) {
                throw new AccountingActionException('invoice_number_taken');
            }

            $invoice = $this->create($order, $user, $type, trim($data['number']), $data['issued_on'], [
                'source' => 'crm_registered',
                'esf_id' => $data['esf_id'] ?? null,
                'code_warnings' => $warnings === [] ? null : $warnings,
            ], $lines);

            foreach ($lines as [$line, $quantity]) {
                $line->forceFill(['invoiced_qty' => bcadd((string) $line->invoiced_qty, $quantity, Quantity::SCALE)])->save();
            }

            $this->ledger->write(EntryType::InvoiceIssued, $invoice->gross_minor, $invoice->currency, $invoice->issued_on, ['customer_id' => $order->customer_id, 'order_id' => $order->id, 'invoice_id' => $invoice->id], $user);
            $order->forceFill(['invoiced_gross_minor' => $order->invoiced_gross_minor + $invoice->gross_minor])->save();
            app(MarkOrderInvoiced::class)->handle($order->fresh(), $user);
            $this->outbox->publishInvoice('invoice.registered', $invoice);

            return $invoice;
        });
    }

    /**
     * @param  iterable<OrderLine>  $lines
     * @return list<array{line: int, name: string, code: string, mode: string}>
     */
    protected function validateCodes(iterable $lines, bool $acknowledged): array
    {
        $problems = $this->codes->check($lines);

        if (InvoiceCodeValidator::blocks($problems)) {
            throw new AccountingActionException('codes_blocking', ['lines' => $this->describe(array_filter($problems, fn (array $p): bool => $p['mode'] === 'block'))]);
        }

        if ($problems !== [] && ! $acknowledged) {
            throw new AccountingActionException('codes_missing', ['lines' => $this->describe($problems)]);
        }

        return $problems;
    }

    /**
     * @param  array<int, array{line: int, name: string, code: string, mode: string}>  $problems
     */
    protected function describe(array $problems): string
    {
        return collect($problems)->map(fn (array $p): string => $p['line'].'. '.$p['name'].' ('.__('accounting.codes.'.$p['code']).')')->implode('; ');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{0: OrderLine, 1: string, 2: int, 3: int, 4: int}>  $lines
     */
    protected function create(Order $order, User $user, InvoiceType $type, string $number, string $issuedOn, array $attributes, array $lines): Invoice
    {
        $invoice = Invoice::query()->create([
            'type' => $type,
            'status' => InvoiceStatus::Issued,
            'number' => $number,
            'issued_on' => $issuedOn,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'agreement_id' => $order->currentAgreement()?->id,
            'currency' => $order->currency,
            'prices_include_vat' => $order->prices_include_vat,
            'net_minor' => array_sum(array_column($lines, 2)),
            'vat_minor' => array_sum(array_column($lines, 3)),
            'gross_minor' => array_sum(array_column($lines, 4)),
            'document_locale' => $order->document_locale,
            'period' => AccountingPeriods::of($issuedOn),
            'issued_by' => $user->id,
            ...$attributes,
        ]);

        foreach (array_values($lines) as $index => [$line, $quantity, $net, $vat, $gross]) {
            $invoice->lines()->create([
                'order_line_id' => $line->id,
                'position' => $index + 1,
                'item_external_id' => $line->item_external_id,
                'sku' => $line->sku,
                'name' => $line->name,
                'unit_code' => $line->unit_code,
                'unit_name' => $line->unit_name,
                'tax_rate_bp' => $line->tax_rate_bp,
                'is_vat_exempt' => $line->is_vat_exempt,
                'accountant_code' => $line->accountant_code,
                'class_code' => $line->class_code,
                'package_code' => $line->package_code,
                'quantity' => $quantity,
                'unit_price_minor' => $line->unit_price_minor,
                'discount_bp' => $line->discount_bp,
                'line_net_minor' => $net,
                'line_vat_minor' => $vat,
                'line_gross_minor' => $gross,
            ]);
        }

        return $invoice;
    }
}
