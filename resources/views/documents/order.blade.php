{{--
    Order confirmation (Spec 06 section 12), rendered by DocumentRenderer in the document language:
    only the order's own snapshot (lines, chosen option labels such as "2C", amounts, codes per the
    print setting); labels only from documents.*, values via $doc. Black-and-white safe.
--}}
@php
    $currency = $order->currency;
    $m = fn (int $minor): string => $doc->money(\App\Support\Money::of($minor, $currency)->toMajor(), $currency);
    $vatByRate = $order->lines->groupBy('tax_rate_bp')->map(fn ($lines) => $lines->sum('line_vat_minor'))->sortKeys();
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-order-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div class="flex items-center gap-4">
            <img src="{{ \App\Models\Setting::logoUrl() }}" alt="{{ $seller['name'] }}" width="64" height="64" class="size-16 object-contain">
            <div>
                <p class="text-base font-semibold">{{ $seller['name'] }}</p>
                @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
                @if ($seller['tax_id']) <p>{{ __('documents.tax_id') }}: {{ $seller['tax_id'] }}</p> @endif
                @if ($seller['phone']) <p>{{ __('documents.phone') }}: {{ $seller['phone'] }}</p> @endif
            </div>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.order.confirmation_title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $order->number }}</span></p>
            <p>{{ __('documents.order.order_date') }}: {{ $doc->date($order->confirmed_at ?? $order->created_at) }}</p>
            @if ($order->offer)
                <p>{{ __('documents.order.based_on_offer', ['number' => $order->offer->displayNumber()]) }}</p>
            @endif
            <p>{{ __('documents.order.delivery_method') }}: {{ $order->delivery_method->label() }}</p>
        </div>
    </header>

    <section class="grid grid-cols-2 gap-6">
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.seller') }}</p>
            <p class="font-semibold">{{ $seller['name'] }}</p>
            <p>{{ __('documents.prepared_by') }}: {{ $order->owner?->name }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.buyer') }}</p>
            <p class="font-semibold">{{ $order->customer?->legal_name ?? $order->customer?->name }}</p>
            @if ($order->customer?->address) <p>{{ __('documents.address') }}: {{ $order->customer->address }}</p> @endif
            @if ($order->customer?->tax_id) <p>{{ __('documents.tax_id') }}: {{ $order->customer->tax_id }}</p> @endif
            @if ($order->contact) <p>{{ __('documents.contact_person') }}: {{ $order->contact->fullName() }}</p> @endif
        </div>
    </section>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.line_no') }}</th>
                @if ($showImages)<th scope="col" class="w-14 border border-black px-1 py-1.5"><span class="sr-only">{{ __('documents.offer.image') }}</span></th>@endif
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                @if ($showAccountantCode)
                    <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.offer.accountant_code') }}</th>
                @endif
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.qty') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.unit') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.unit_price') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.discount') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->lines as $line)
                <tr class="align-top">
                    <td class="border border-black px-2 py-1.5 font-bold whitespace-nowrap">{{ $line->option_label }}</td>
                    @if ($showImages)<td class="w-14 border border-black p-1 text-center">@php($uri = $line->imageDataUri())@if ($uri)<img src="{{ $uri }}" alt="{{ $line->name }}" width="48" height="48" class="inline-block size-12 object-contain" data-print-image>@else<span class="inline-flex size-12 items-center justify-center border border-dashed border-black/40 text-[9px]" data-print-image-placeholder>{{ __('documents.offer.no_image') }}</span>@endif</td>@endif
                    <td class="border border-black px-2 py-1.5">
                        <span class="font-semibold">{{ $line->name }}</span>
                        <span class="block text-[11px]">{{ $line->sku }}@if ($line->group_title) · {{ $line->group_title }} — {{ __('documents.order.chosen_option', ['label' => $line->option_label]) }}@endif</span>
                    </td>
                    @if ($showAccountantCode)
                        <td class="border border-black px-2 py-1.5">{{ $line->accountant_code }}</td>
                    @endif
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->quantity((string) $line->quantity) }}</td>
                    <td class="border border-black px-2 py-1.5">{{ $line->unit_name }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $m($line->unit_price_minor) }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $line->discount_bp > 0 ? $doc->percent(bcdiv((string) $line->discount_bp, '100', 2)) : '—' }}</td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $m($order->prices_include_vat ? $line->line_gross_minor : $line->line_net_minor) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="ml-auto w-full max-w-sm space-y-1 print-avoid-break">
        <div class="flex justify-between gap-4"><span>{{ __('documents.subtotal') }}</span><span class="whitespace-nowrap">{{ $m($order->net_minor) }}</span></div>
        @foreach ($vatByRate as $rate => $vat)
            <div class="flex justify-between gap-4">
                <span>{{ (int) $rate === 0 ? __('documents.offer.vat_exempt') : __('documents.vat_rate', ['rate' => $doc->percent(bcdiv((string) $rate, '100', 2))]) }}</span>
                <span class="whitespace-nowrap">{{ $m((int) $vat) }}</span>
            </div>
        @endforeach
        <div class="flex justify-between gap-4 border-t border-black pt-1 font-bold"><span>{{ __('documents.total_with_vat') }}</span><span class="whitespace-nowrap">{{ $m($order->gross_minor) }}</span></div>
        <p><span class="font-semibold">{{ __('documents.amount_in_words_label') }}:</span> <span data-amount-in-words>{{ $doc->amountInWords(\App\Support\Money::of($order->gross_minor, $currency)->toMajor(), $currency) }}</span></p>
    </section>

    <section class="space-y-1">
        @if ($order->payment_terms_text || $order->payment_terms_days !== null)
            <p><span class="font-semibold">{{ __('documents.payment_terms') }}:</span> {{ $order->payment_terms_text }}@if ($order->payment_terms_days !== null) ({{ __('documents.offer.payment_days', ['days' => $order->payment_terms_days]) }})@endif</p>
        @endif
        @if ($order->delivery_terms_text) <p><span class="font-semibold">{{ __('documents.delivery_terms') }}:</span> {{ $order->delivery_terms_text }}</p> @endif
        @if ($order->warranty_text) <p><span class="font-semibold">{{ __('documents.warranty') }}:</span> {{ $order->warranty_text }}</p> @endif
        @if ($order->delivery_address) <p><span class="font-semibold">{{ __('documents.order.delivery_address') }}:</span> {{ $order->delivery_address }}</p> @endif
        @if ($order->promised_delivery_on) <p><span class="font-semibold">{{ __('documents.order.delivery_date') }}:</span> {{ $doc->longDate($order->promised_delivery_on) }}</p> @endif
    </section>

    <section class="grid grid-cols-2 gap-6 pt-6 print-avoid-break">
        @foreach ([__('documents.seller'), __('documents.buyer')] as $party)
            <div class="space-y-3">
                <p class="text-xs font-semibold tracking-wide uppercase">{{ $party }}</p>
                <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.signature') }}:</span><span class="flex-1 border-b border-black"></span></div>
                <p>{{ __('documents.stamp') }}</p>
            </div>
        @endforeach
    </section>
</article>
