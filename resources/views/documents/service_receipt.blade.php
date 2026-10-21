{{--
    Service acceptance receipt (Spec 09 §9.3): ticket, customer, unit, warranty status, problem,
    accepted date, signatures. Labels from documents.service_receipt.*, dates via $doc.
--}}
<article class="space-y-4 text-[12.5px] leading-snug text-black" data-service-receipt>
    <header class="border-b-2 border-black pb-3">
        <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.service_receipt.title') }}</h1>
        <p>{{ $seller['name'] }} · {{ __('documents.number') }} {{ $ticket->number }} · {{ $doc->date($ticket->created_at) }}</p>
        <p>{{ __('documents.buyer') }}: {{ $ticket->organization?->legal_name ?? $ticket->organization?->name }}</p>
    </header>

    <table class="w-full border-collapse">
        <tbody>
            <tr><th scope="row" class="w-1/3 border border-black px-2 py-1.5 text-left">{{ __('documents.item') }}</th><td class="border border-black px-2 py-1.5">{{ $ticket->asset->displayName() }}</td></tr>
            <tr><th scope="row" class="border border-black px-2 py-1.5 text-left">{{ __('documents.serials.label') }}</th><td class="border border-black px-2 py-1.5 font-mono">{{ $ticket->asset->serial_raw }}</td></tr>
            <tr><th scope="row" class="border border-black px-2 py-1.5 text-left">{{ __('documents.service_receipt.warranty') }}</th>
                <td class="border border-black px-2 py-1.5">{{ $ticket->under_warranty ? __('documents.service_receipt.under_warranty', ['date' => $doc->date($ticket->warranty_until)]) : __('documents.service_receipt.no_warranty') }}</td></tr>
            <tr><th scope="row" class="border border-black px-2 py-1.5 text-left">{{ __('documents.service_receipt.problem') }}</th><td class="border border-black px-2 py-1.5 whitespace-pre-line">{{ $ticket->problem }}</td></tr>
            <tr><th scope="row" class="border border-black px-2 py-1.5 text-left">{{ __('documents.service_receipt.contact') }}</th><td class="border border-black px-2 py-1.5">{{ collect([$ticket->contact_name, $ticket->contact_phone, $ticket->location])->filter()->join(' · ') ?: '—' }}</td></tr>
            <tr><th scope="row" class="border border-black px-2 py-1.5 text-left">{{ __('documents.service_receipt.accepted') }}</th><td class="border border-black px-2 py-1.5">{{ $ticket->accepted_at ? $doc->date($ticket->accepted_at) : '—' }}</td></tr>
            @if ($ticket->replacement_note)
                <tr><th scope="row" class="border border-black px-2 py-1.5 text-left">{{ __('documents.service_receipt.replacement') }}</th><td class="border border-black px-2 py-1.5">{{ $ticket->replacement_note }}</td></tr>
            @endif
        </tbody>
    </table>

    <p class="text-xs">{{ __('documents.service_receipt.terms') }}</p>

    <div class="grid grid-cols-2 gap-6 pt-6">
        <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.service_receipt.handed_over') }}:</span><span class="flex-1 border-b border-black"></span></div>
        <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.service_receipt.received_by') }}:</span><span class="flex-1 border-b border-black"></span></div>
    </div>
</article>
