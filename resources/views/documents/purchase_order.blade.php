{{--
    Purchase order to a supplier (purchase-tender skill section 6): award snapshot lines, prices,
    Incoterm, delivery and payment terms. Labels from documents.*, values via $doc.
--}}
@php
    $m = fn (int $minor): string => $doc->money(\App\Support\Money::of($minor, $po->currency)->toMajor(), $po->currency);
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-purchase-order-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
            @if ($seller['tax_id']) <p>{{ __('documents.tax_id') }}: {{ $seller['tax_id'] }}</p> @endif
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.purchase_order.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $po->number }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($po->confirmed_at ?? $po->created_at) }}</p>
            @if ($po->award?->tender)<p>{{ __('documents.purchase_order.based_on', ['number' => $po->award->tender->number]) }}</p>@endif
        </div>
    </header>

    <section>
        <p><span class="font-semibold">{{ __('documents.purchase_order.supplier') }}:</span> {{ $po->supplier?->legal_name ?? $po->supplier?->name }}@if ($po->supplier?->tax_id) · {{ __('documents.tax_id') }}: {{ $po->supplier->tax_id }}@endif</p>
        <p><span class="font-semibold">{{ __('documents.purchase_order.incoterm') }}:</span> {{ trim($po->incoterm.' '.$po->incoterm_place) ?: '—' }} (Incoterms® 2020)</p>
        <p><span class="font-semibold">{{ __('documents.purchase_order.delivery') }}:</span> {{ $po->destinationWarehouse?->name ?? '—' }}@if ($po->required_delivery_date) · {{ $doc->date($po->required_delivery_date) }}@endif</p>
        <p><span class="font-semibold">{{ __('documents.purchase_order.payment_terms') }}:</span> {{ $po->payment_terms ?? '—' }}</p>
    </section>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.line_no') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.qty') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.unit_price') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($po->lines as $line)
                <tr class="align-top">
                    <td class="border border-black px-2 py-1.5">{{ $line->position }}</td>
                    <td class="border border-black px-2 py-1.5"><span class="font-semibold">{{ $line->name }}</span>@if ($line->sku) <span class="text-[11px]">{{ $line->sku }}</span>@endif @if ($line->offered_brand || $line->offered_model)<br>{{ trim($line->offered_brand.' '.$line->offered_model) }}@endif</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->quantity((string) $line->quantity) }} {{ $line->unit_code }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $m($line->unit_price_minor) }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $m($line->line_total_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><th scope="row" colspan="4" class="border border-black px-2 py-1.5 text-right">{{ __('documents.total') }}</th><td class="border border-black px-2 py-1.5 text-right font-semibold whitespace-nowrap">{{ $m($po->subtotal_minor) }}</td></tr>
        </tfoot>
    </table>
</article>
