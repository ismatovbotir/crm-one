{{--
    Invoice (Spec 06 section 12), rendered by DocumentRenderer in the document language.
    Proforma: "This is not a VAT invoice", our bank account in the invoice currency, amount due and
    pay-by date. Tax documents: a reference copy of the registered document (the legal document is
    the electronic one). Code columns per `accounting.invoice_print_columns`. Values via $doc.
--}}
@php
    $currency = $invoice->currency;
    $m = fn (int $minor): string => $doc->money(\App\Support\Money::of($minor, $currency)->toMajor(), $currency);
    $isProforma = $invoice->type === \App\Enums\InvoiceType::Proforma;
    $vatByRate = $invoice->lines->groupBy('tax_rate_bp')->map(fn ($lines) => $lines->sum('line_vat_minor'))->sortKeys();
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-invoice-document data-type="{{ $invoice->type->value }}">
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div class="flex items-center gap-4">
            <img src="{{ \App\Models\Setting::logoUrl() }}" alt="{{ $seller['name'] }}" width="64" height="64" class="size-16 object-contain">
            <div>
                <p class="text-base font-semibold">{{ $seller['name'] }}</p>
                @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
                @if ($seller['tax_id']) <p>{{ __('documents.tax_id') }}: {{ $seller['tax_id'] }}</p> @endif
                @if ($seller['vat_code']) <p>{{ __('documents.agreement.vat_code') }}: {{ $seller['vat_code'] }}</p> @endif
            </div>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.invoice.types.'.$invoice->type->value) }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $invoice->number }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($invoice->issued_on) }}</p>
            @if ($invoice->order) <p>{{ __('documents.order.title') }}: {{ $invoice->order->number }}</p> @endif
        </div>
    </header>

    @if ($isProforma)
        <p class="border border-black px-2 py-1 text-center font-semibold" data-not-tax-invoice>{{ __('documents.invoice.not_tax_invoice') }}</p>
    @else
        <p class="border border-black px-2 py-1 text-center" data-reference-copy>{{ __('documents.invoice.reference_copy', ['esf' => $invoice->esf_id ?: $invoice->number]) }}</p>
    @endif

    <section class="grid grid-cols-2 gap-6">
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.seller') }}</p>
            <p class="font-semibold">{{ $seller['name'] }}</p>
            @if ($isProforma && $invoice->bankAccount)
                <p>{{ __('documents.invoice.bank') }}: {{ $invoice->bankAccount->bank_name }}</p>
                <p>{{ __('documents.invoice.bank_code') }}: {{ $invoice->bankAccount->mfo }}</p>
                <p>{{ __('documents.invoice.account') }}: <span class="font-mono">{{ $invoice->bankAccount->account_number }}</span></p>
            @endif
        </div>
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.buyer') }}</p>
            <p class="font-semibold">{{ $invoice->customer?->legal_name ?? $invoice->customer?->name }}</p>
            @if ($invoice->customer?->address) <p>{{ __('documents.address') }}: {{ $invoice->customer->address }}</p> @endif
            @if ($invoice->customer?->tax_id) <p>{{ __('documents.tax_id') }}: {{ $invoice->customer->tax_id }}</p> @endif
        </div>
    </section>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.line_no') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                @if ($columns['accountant_code']) <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.invoice.accountant_code') }}</th> @endif
                @if ($columns['class_code']) <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.invoice.class_code') }}</th> @endif
                @if ($columns['package_code']) <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.invoice.package_code') }}</th> @endif
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.qty') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.unit') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.unit_price') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.vat') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.total_with_vat') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr class="align-top">
                    <td class="border border-black px-2 py-1.5">{{ $line->position }}</td>
                    <td class="border border-black px-2 py-1.5">{{ $line->name }}</td>
                    @if ($columns['accountant_code']) <td class="border border-black px-2 py-1.5 font-mono">{{ $line->accountant_code }}</td> @endif
                    @if ($columns['class_code']) <td class="border border-black px-2 py-1.5 font-mono">{{ $line->class_code }}</td> @endif
                    @if ($columns['package_code']) <td class="border border-black px-2 py-1.5 font-mono">{{ $line->package_code }}</td> @endif
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->quantity((string) $line->quantity) }}</td>
                    <td class="border border-black px-2 py-1.5">{{ $line->unit_name }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $m($line->unit_price_minor) }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $line->is_vat_exempt ? __('documents.offer.vat_exempt') : $m($line->line_vat_minor) }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $m($line->line_gross_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="ml-auto w-full max-w-sm space-y-1 print-avoid-break">
        <div class="flex justify-between gap-4"><span>{{ __('documents.subtotal') }}</span><span class="whitespace-nowrap">{{ $m($invoice->net_minor) }}</span></div>
        @foreach ($vatByRate as $rate => $vat)
            <div class="flex justify-between gap-4"><span>{{ (int) $rate === 0 ? __('documents.offer.vat_exempt') : __('documents.vat_rate', ['rate' => $doc->percent(bcdiv((string) $rate, '100', 2))]) }}</span><span class="whitespace-nowrap">{{ $m((int) $vat) }}</span></div>
        @endforeach
        <div class="flex justify-between gap-4 border-t border-black pt-1 font-bold"><span>{{ __('documents.total_with_vat') }}</span><span class="whitespace-nowrap">{{ $m($invoice->gross_minor) }}</span></div>
        @if ($isProforma && $invoice->requested_amount_minor !== null)
            <div class="flex justify-between gap-4 font-bold" data-amount-due><span>{{ __('documents.invoice.amount_due') }}</span><span class="whitespace-nowrap">{{ $m($invoice->requested_amount_minor) }}</span></div>
            <p><span class="font-semibold">{{ __('documents.amount_in_words_label') }}:</span> {{ $doc->amountInWords(\App\Support\Money::of($invoice->requested_amount_minor, $currency)->toMajor(), $currency) }}</p>
        @endif
    </section>

    @if ($isProforma)
        <section class="space-y-1">
            <p><span class="font-semibold">{{ __('documents.invoice.payment_purpose') }}:</span> {{ __('documents.invoice.payment_purpose_text', ['order' => $invoice->order?->number, 'date' => $doc->date($invoice->order?->confirmed_at ?? $invoice->order?->created_at)]) }}</p>
            @if ($invoice->due_on) <p class="font-semibold">{{ __('documents.invoice.pay_by', ['date' => $doc->longDate($invoice->due_on)]) }}</p> @endif
        </section>
    @endif

    <section class="grid grid-cols-2 gap-6 pt-6 print-avoid-break">
        @foreach ([['documents.invoice.director', $director], ['documents.invoice.chief_accountant', $chiefAccountant]] as [$label, $name])
            <div class="space-y-3">
                <p class="text-xs font-semibold tracking-wide uppercase">{{ __($label) }}</p>
                <div class="flex items-end gap-3"><span class="flex-1 border-b border-black"></span><span>{{ $name }}</span></div>
            </div>
        @endforeach
    </section>
</article>
