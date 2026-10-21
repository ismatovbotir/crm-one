{{--
    Stock-off act (Spec 06 27.6): reason, lines with quantities, serials and estimated value,
    approvals and signatures. Labels from documents.*, values via $doc.
--}}
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-stock-off-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            <p>{{ __('documents.delivery_note.warehouse') }}: {{ $act->warehouse?->name }}</p>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.stock_off.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $act->number }}</span></p>
            <p>{{ __('documents.date') }}: {{ $doc->date($act->posted_at ?? $act->created_at) }}</p>
            <p>{{ __('documents.stock_off.status') }}: {{ __('stock_off.status.'.$act->status->value) }}</p>
        </div>
    </header>

    <section class="grid gap-1">
        <p><span class="font-semibold">{{ __('documents.stock_off.reason') }}:</span> {{ __('stock_off.reasons.'.$act->reason->value) }}</p>
        @if ($act->note) <p><span class="font-semibold">{{ __('documents.stock_off.note') }}:</span> {{ $act->note }}</p> @endif
        @if ($act->goodsReceipt) <p><span class="font-semibold">{{ __('documents.stock_off.receipt') }}:</span> {{ $act->goodsReceipt->number }}@if ($act->supplierPartner) · {{ $act->supplierPartner->name }} ({{ $act->supplierPartner->tax_id }})@endif</p> @endif
        @if ($act->claim_status !== 'none') <p><span class="font-semibold">{{ __('documents.stock_off.claim') }}:</span> {{ __('stock_off.claim_status.'.$act->claim_status) }} {{ $act->claim_ref }}</p> @endif
    </section>

    <table class="w-full border-collapse">
        <thead>
            <tr class="text-left text-xs font-semibold">
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.line_no') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.item') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.stock_off.quantity') }}</th>
                <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.stock_off.value') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($act->lines as $line)
                <tr class="align-top">
                    <td class="border border-black px-2 py-1.5">{{ $line->position }}</td>
                    <td class="border border-black px-2 py-1.5">
                        <span class="font-semibold">{{ $line->item?->name }}</span> <span class="text-[11px]">{{ $line->item?->sku }}</span>
                        @if ($line->is_serialized && $line->serials->isNotEmpty())
                            @include('documents.partials.serials', ['serials' => $line->serials->pluck('serial_number')->all(), 'inlineMax' => $inlineMax])
                        @endif
                        @if ($line->note) <p class="text-[11px]">{{ $line->note }}</p> @endif
                    </td>
                    <td class="border border-black px-2 py-1.5 text-right">{{ $doc->quantity((string) $line->quantity) }}</td>
                    <td class="border border-black px-2 py-1.5 text-right">{{ $line->value_minor !== null ? $doc->money(\App\Support\Money::of($line->value_minor, (string) $line->currency)->toMajor(), (string) $line->currency) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="grid grid-cols-3 gap-6 pt-6 text-xs">
        <div><p class="font-semibold">{{ __('documents.stock_off.prepared_by') }}</p><p class="mt-6 border-t border-black pt-1">{{ $act->creator?->name }}</p></div>
        <div><p class="font-semibold">{{ __('documents.stock_off.approved_by') }}</p><p class="mt-6 border-t border-black pt-1">{{ $act->decider?->name }}</p></div>
        <div><p class="font-semibold">{{ __('documents.stock_off.commission') }}</p><p class="mt-6 border-t border-black pt-1">&nbsp;</p></div>
    </section>
</article>
