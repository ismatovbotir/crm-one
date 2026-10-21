{{--
    Goods receipt (Spec 06 21.10): expected / received / damaged quantities per line and the serial
    numbers registered. Labels from documents.*, values via $doc.
--}}
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-goods-receipt-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            <p>{{ __('documents.delivery_note.warehouse') }}: {{ $receipt->warehouse?->name }}</p>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.goods_receipt.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $receipt->number }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($receipt->received_on) }}</p>
            @if ($receipt->supplier_doc_ref) <p>{{ $receipt->counterparty_name }} · {{ $receipt->supplier_doc_ref }}</p> @endif
        </div>
    </header>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.line_no') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.goods_receipt.expected') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.goods_receipt.received') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.goods_receipt.damaged') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($receipt->lines as $line)
                <tr class="align-top">
                    <td class="border border-black px-2 py-1.5">{{ $line->position }}</td>
                    <td class="border border-black px-2 py-1.5">
                        <span class="font-semibold">{{ $line->item?->name }}</span> <span class="text-[11px]">{{ $line->item?->sku }}</span>
                        @if ($line->is_serialized && $line->serialRows->isNotEmpty())
                            @include('documents.partials.serials', ['serials' => $line->serialRows->pluck('serial_number')->all(), 'inlineMax' => $inlineMax])
                        @endif
                    </td>
                    <td class="border border-black px-2 py-1.5 text-right">{{ $line->expected_qty !== null ? $doc->quantity((string) $line->expected_qty) : '—' }}</td>
                    <td class="border border-black px-2 py-1.5 text-right">{{ $doc->quantity((string) $line->received_qty) }}</td>
                    <td class="border border-black px-2 py-1.5 text-right">{{ $doc->quantity((string) $line->damaged_qty) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</article>
