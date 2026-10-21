{{--
    Sales receipt (Spec 06 section 25, DEC-25.4): the retail payment document, rendered by
    DocumentRenderer in the document language. Items with amounts, the payments allocated to the
    order and the amount received. Not a VAT invoice. Values via $doc.
--}}
@php
    $currency = $invoice->currency;
    $m = fn (int $minor): string => $doc->money(\App\Support\Money::of($minor, $currency)->toMajor(), $currency);
    $vatByRate = $invoice->lines->groupBy('tax_rate_bp')->map(fn ($lines) => $lines->sum('line_vat_minor'))->sortKeys();
    $received = $payments->sum('amount');
@endphp
<article class="mx-auto max-w-2xl space-y-5 text-[12.5px] leading-snug text-black" data-receipt-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div class="flex items-center gap-4">
            <img src="{{ \App\Models\Setting::logoUrl() }}" alt="{{ $seller['name'] }}" width="56" height="56" class="size-14 object-contain">
            <div>
                <p class="text-base font-semibold">{{ $seller['name'] }}</p>
                @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
                @if ($seller['tax_id']) <p>{{ __('documents.tax_id') }}: {{ $seller['tax_id'] }}</p> @endif
            </div>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.sales_receipt.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $invoice->number }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($invoice->issued_on) }}</p>
            @if ($invoice->order) <p>{{ __('documents.order.title') }}: {{ $invoice->order->number }}</p> @endif
        </div>
    </header>

    <section>
        <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.buyer') }}</p>
        <p class="font-semibold">{{ $invoice->customer?->legal_name ?? $invoice->customer?->name }}</p>
        @if ($invoice->customer?->phone) <p>{{ __('documents.phone') }}: {{ $invoice->customer->phone }}</p> @endif
    </section>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border-b border-black px-1 py-1.5">{{ __('documents.line_no') }}</th>
                <th scope="col" class="border-b border-black px-1 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border-b border-black px-1 py-1.5 text-right">{{ __('documents.qty') }}</th>
                <th scope="col" class="border-b border-black px-1 py-1.5 text-right">{{ __('documents.unit_price') }}</th>
                <th scope="col" class="border-b border-black px-1 py-1.5 text-right">{{ __('documents.total_with_vat') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr class="align-top">
                    <td class="border-b border-black/40 px-1 py-1.5">{{ $line->position }}</td>
                    <td class="border-b border-black/40 px-1 py-1.5">{{ $line->name }}@if ($line->sku) <span class="font-mono text-[11px]">· {{ $line->sku }}</span>@endif</td>
                    <td class="border-b border-black/40 px-1 py-1.5 text-right whitespace-nowrap">{{ $doc->quantity((string) $line->quantity) }} {{ $line->unit_name }}</td>
                    <td class="border-b border-black/40 px-1 py-1.5 text-right whitespace-nowrap">{{ $m($line->unit_price_minor) }}</td>
                    <td class="border-b border-black/40 px-1 py-1.5 text-right whitespace-nowrap">{{ $m($line->line_gross_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="ml-auto w-full max-w-xs space-y-1 print-avoid-break">
        @foreach ($vatByRate as $rate => $vat)
            <div class="flex justify-between gap-4"><span>{{ (int) $rate === 0 ? __('documents.offer.vat_exempt') : __('documents.sales_receipt.vat_included', ['rate' => $doc->percent(bcdiv((string) $rate, '100', 2))]) }}</span><span class="whitespace-nowrap">{{ $m((int) $vat) }}</span></div>
        @endforeach
        <div class="flex justify-between gap-4 border-t border-black pt-1 text-sm font-bold"><span>{{ __('documents.total') }}</span><span class="whitespace-nowrap">{{ $m($invoice->gross_minor) }}</span></div>
        <p><span class="font-semibold">{{ __('documents.amount_in_words_label') }}:</span> {{ $doc->amountInWords(\App\Support\Money::of($invoice->gross_minor, $currency)->toMajor(), $currency) }}</p>
    </section>

    <section class="space-y-1 print-avoid-break" data-receipt-payments>
        <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.sales_receipt.payments') }}</p>
        @forelse ($payments as $payment)
            <div class="flex justify-between gap-4">
                <span>{{ $payment['method']?->label() ?? '—' }} · {{ $doc->date($payment['paid_on']) }} · {{ $payment['number'] }}</span>
                <span class="whitespace-nowrap">{{ $m($payment['amount']) }}</span>
            </div>
        @empty
            <p>—</p>
        @endforelse
        <div class="flex justify-between gap-4 border-t border-black pt-1 font-semibold"><span>{{ __('documents.sales_receipt.received') }}</span><span class="whitespace-nowrap">{{ $m((int) $received) }}</span></div>
    </section>

    <p class="border border-black px-2 py-1 text-center">{{ __('documents.sales_receipt.not_tax_invoice') }}</p>

    <section class="grid grid-cols-2 gap-6 pt-4 print-avoid-break">
        <div class="space-y-3">
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.sales_receipt.issued_by') }}</p>
            <div class="flex items-end gap-3"><span class="flex-1 border-b border-black"></span><span>{{ $invoice->issuer?->name }}</span></div>
        </div>
        <p class="self-end text-right font-semibold">{{ __('documents.sales_receipt.thanks') }}</p>
    </section>
</article>
