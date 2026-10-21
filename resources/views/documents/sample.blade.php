{{--
    Sample offer rendered through DocumentRenderer (inside withLocale): every label uses
    documents.* keys and every date/number goes through $doc, so it prints in the chosen
    document language. Real offer/order/agreement views follow the same pattern.
--}}
<article class="space-y-6 text-[13px] leading-snug">
    <header class="flex items-start justify-between gap-6 border-b-2 border-brand-600 pb-4">
        <div class="flex items-center gap-4">
            <img src="{{ \App\Models\Setting::logoUrl() }}" alt="{{ $offer['seller']['name'] }}" width="64" height="64" class="size-16 object-contain">
            <div>
                <p class="text-base font-semibold">{{ $offer['seller']['name'] }}</p>
                <p class="text-muted">{{ $offer['seller']['address'] }}</p>
                <p class="text-muted">{{ __('documents.tax_id') }}: {{ $offer['seller']['tax_id'] }}</p>
            </div>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.offer.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $offer['number'] }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($offer['date']) }}</p>
            <p>{{ __('documents.offer.valid_until') }}: <span class="font-semibold">{{ $doc->longDate($offer['valid_until']) }}</span></p>
        </div>
    </header>

    <p class="rounded border border-brand-600 bg-brand-50 px-3 py-2 text-xs text-brand-700">{{ __('documents.sample.notice') }}</p>

    <section class="grid grid-cols-2 gap-6">
        <div>
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('documents.seller') }}</p>
            <p class="font-semibold">{{ $offer['seller']['name'] }}</p>
            <p>{{ __('documents.address') }}: {{ $offer['seller']['address'] }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('documents.buyer') }}</p>
            <p class="font-semibold">{{ $offer['customer']['name'] }}</p>
            <p>{{ __('documents.address') }}: {{ $offer['customer']['address'] }}</p>
            <p>{{ __('documents.tax_id') }}: {{ $offer['customer']['tax_id'] }}</p>
        </div>
    </section>

    <p>{{ __('documents.offer.intro') }}</p>

    <table class="w-full border-collapse">
        <thead>
            <tr class="bg-sidebar text-left text-xs font-semibold text-sidebar-text">
                <th scope="col" class="border border-line px-2 py-1.5">{{ __('documents.line_no') }}</th>
                <th scope="col" class="border border-line px-2 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border border-line px-2 py-1.5 text-right">{{ __('documents.qty') }}</th>
                <th scope="col" class="border border-line px-2 py-1.5">{{ __('documents.unit') }}</th>
                <th scope="col" class="border border-line px-2 py-1.5 text-right">{{ __('documents.unit_price') }}</th>
                <th scope="col" class="border border-line px-2 py-1.5 text-right">{{ __('documents.discount') }}</th>
                <th scope="col" class="border border-line px-2 py-1.5 text-right">{{ __('documents.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($offer['lines'] as $line)
                <tr class="print-avoid-break">
                    <td class="border border-line px-2 py-1.5">{{ $loop->iteration }}</td>
                    <td class="border border-line px-2 py-1.5">{{ $line['name'] }}</td>
                    <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->quantity($line['quantity']) }}</td>
                    <td class="border border-line px-2 py-1.5">{{ __('documents.units.'.$line['unit']) }}</td>
                    <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->number($line['unit_price']) }}</td>
                    <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->percent($line['discount']) }}</td>
                    <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->number($line['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="print-avoid-break">
            <tr>
                <td colspan="6" class="border border-line px-2 py-1.5 text-right">{{ __('documents.subtotal') }}</td>
                <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->number($offer['subtotal']) }}</td>
            </tr>
            <tr>
                <td colspan="6" class="border border-line px-2 py-1.5 text-right">{{ __('documents.vat_rate', ['rate' => $doc->percent($offer['vat_rate'])]) }}</td>
                <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->number($offer['vat']) }}</td>
            </tr>
            <tr class="bg-sidebar font-semibold text-sidebar-text">
                <td colspan="6" class="border border-line px-2 py-1.5 text-right">{{ __('documents.total_with_vat') }}</td>
                <td class="border border-line px-2 py-1.5 text-right whitespace-nowrap" data-total>{{ $doc->money($offer['total'], $offer['currency']) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="print-avoid-break">
        <span class="font-semibold">{{ __('documents.amount_in_words_label') }}:</span>
        <span data-amount-in-words>{{ $doc->amountInWords($offer['total'], $offer['currency']) }}</span>
    </p>

    <section class="print-avoid-break space-y-1">
        <p><span class="font-semibold">{{ __('documents.payment_terms') }}:</span> {{ __('documents.sample.payment_terms') }}</p>
        <p><span class="font-semibold">{{ __('documents.delivery_terms') }}:</span> {{ __('documents.sample.delivery_terms') }}</p>
        <p class="text-muted">{{ __('documents.offer.acceptance') }}</p>
    </section>

    <footer class="print-avoid-break grid grid-cols-2 gap-10 pt-8">
        <div>
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('documents.prepared_by') }}</p>
            <p class="mt-1 font-semibold">{{ $offer['prepared_by']->name }}</p>
            <p class="text-muted">{{ __('documents.position') }}: {{ $offer['prepared_by']->role->label() }}</p>
        </div>
        <div class="space-y-6">
            <div class="flex items-end gap-3">
                <span class="whitespace-nowrap">{{ __('documents.signature') }}:</span>
                <span class="flex-1 border-b border-ink"></span>
            </div>
            <p class="text-muted">{{ __('documents.stamp') }}</p>
        </div>
    </footer>
</article>
