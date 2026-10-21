{{--
    Delivery note = sales document (Spec 06 21.10), rendered by DocumentRenderer in the document
    language: lines with quantities and the serial numbers registered for each line (inline, or
    ranges plus an annex for long lists). Labels only from documents.*, values via $doc.
--}}
@php
    $order = $delivery->order;
    $annex = [];
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-delivery-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
            @if ($seller['tax_id']) <p>{{ __('documents.tax_id') }}: {{ $seller['tax_id'] }}</p> @endif
        </div>
        <div class="text-right">
            {{-- Spec 06 section 31: self pick-up prints as a handover act with who collected the goods. --}}
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ $order?->isPickup() ? __('documents.delivery_note.pickup_title') : __('documents.delivery_note.title') }}</h1>
            @if ($order?->isPickup())
                <p>{{ __('documents.delivery_note.collected_by', ['person' => $delivery->pickup_person ?? '—', 'document' => $delivery->pickup_document ?? '—']) }}</p>
            @endif
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $delivery->number }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($delivery->delivered_on ?? $delivery->created_at) }}</p>
            <p>{{ __('documents.order.title') }}: {{ $order?->number }}</p>
        </div>
    </header>

    <section class="grid grid-cols-2 gap-6">
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.seller') }}</p>
            <p class="font-semibold">{{ $seller['name'] }}</p>
            @if ($delivery->warehouse) <p>{{ __('documents.delivery_note.warehouse') }}: {{ $delivery->warehouse->name }}</p> @endif
        </div>
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.buyer') }}</p>
            <p class="font-semibold">{{ $order?->customer?->legal_name ?? $order?->customer?->name }}</p>
            @if ($order?->delivery_address) <p>{{ __('documents.order.delivery_address') }}: {{ $order->delivery_address }}</p> @endif
        </div>
    </section>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.line_no') }}</th>
                @if ($showImages)<th scope="col" class="w-14 border border-black px-1 py-1.5"><span class="sr-only">{{ __('documents.offer.image') }}</span></th>@endif
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.qty') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.unit') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($delivery->lines as $line)
                @continue(bccomp((string) $line->quantity, '0', 3) <= 0)
                @php($serials = $line->is_serialized ? $line->serialRows->pluck('serial_number')->all() : [])
                @php($serials !== [] && count($serials) > $inlineMax ? $annex[] = ['name' => $line->orderLine?->name, 'serials' => $serials] : null)
                <tr class="align-top">
                    <td class="border border-black px-2 py-1.5">{{ $loop->iteration }}</td>
                    @if ($showImages)<td class="w-14 border border-black p-1 text-center">@php($uri = $line->orderLine?->imageDataUri())@if ($uri)<img src="{{ $uri }}" alt="{{ $line->orderLine?->name }}" width="48" height="48" class="inline-block size-12 object-contain" data-print-image>@else<span class="inline-flex size-12 items-center justify-center border border-dashed border-black/40 text-[9px]" data-print-image-placeholder>{{ __('documents.offer.no_image') }}</span>@endif</td>@endif
                    <td class="border border-black px-2 py-1.5">
                        <span class="font-semibold">{{ $line->orderLine?->name }}</span>
                        <span class="block text-[11px]">{{ $line->orderLine?->sku }}</span>
                        @if ($serials !== [])
                            @include('documents.partials.serials', ['serials' => $serials, 'inlineMax' => $inlineMax])
                        @endif
                    </td>
                    <td class="border border-black px-2 py-1.5 text-right whitespace-nowrap">{{ $doc->quantity((string) $line->quantity) }}</td>
                    <td class="border border-black px-2 py-1.5">{{ $line->orderLine?->unit_name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="grid grid-cols-2 gap-6 pt-6 print-avoid-break">
        @foreach ([__('documents.delivery_note.released_by'), __('documents.delivery_note.received_by')] as $party)
            <div class="space-y-3">
                <p class="text-xs font-semibold tracking-wide uppercase">{{ $party }}</p>
                <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.signature') }}:</span><span class="flex-1 border-b border-black"></span></div>
            </div>
        @endforeach
    </section>

    @if ($annex !== [])
        <section class="break-before-page space-y-3" data-serial-annex>
            <h2 class="text-sm font-bold uppercase">{{ __('documents.serials.annex_title') }}</h2>
            @foreach ($annex as $entry)
                <div>
                    <p class="font-semibold">{{ $entry['name'] }} ({{ count($entry['serials']) }})</p>
                    <p class="font-mono text-[11px] leading-relaxed">{{ implode(', ', $entry['serials']) }}</p>
                </div>
            @endforeach
        </section>
    @endif
</article>
