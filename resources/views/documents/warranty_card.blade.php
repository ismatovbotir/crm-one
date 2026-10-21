{{--
    Warranty card (Spec 06 21.10): one block per sold serial of the delivery note with item, serial,
    sale date and warranty end. Labels from documents.*, dates via $doc.
--}}
<article class="space-y-4 text-[12.5px] leading-snug text-black" data-warranty-card>
    <header class="border-b-2 border-black pb-3">
        <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.warranty_card.title') }}</h1>
        <p>{{ $seller['name'] }} · {{ __('documents.number') }} {{ $delivery->number }} · {{ $doc->date($delivery->delivered_on) }}</p>
        <p>{{ __('documents.buyer') }}: {{ $delivery->order?->customer?->legal_name ?? $delivery->order?->customer?->name }}</p>
    </header>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.serials.label') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.date') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.warranty_card.warranty_until') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($serials as $serial)
                <tr>
                    <td class="border border-black px-2 py-1.5">{{ $serial->item?->name }}</td>
                    <td class="border border-black px-2 py-1.5 font-mono">{{ $serial->serial_normalized }}</td>
                    <td class="border border-black px-2 py-1.5">{{ $doc->date($serial->sold_at) }}</td>
                    <td class="border border-black px-2 py-1.5">{{ $serial->warranty_until ? $doc->date($serial->warranty_until) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="border border-black px-2 py-1.5">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="grid grid-cols-2 gap-6 pt-6">
        <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.signature') }}:</span><span class="flex-1 border-b border-black"></span></div>
        <p>{{ __('documents.stamp') }}</p>
    </div>
</article>
