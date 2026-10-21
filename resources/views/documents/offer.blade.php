{{--
    Printed commercial offer (Spec 05 section 11), rendered by DocumentRenderer inside the document
    language: labels only from documents.*, dates/numbers/money/amount in words only via $doc.
    Must read correctly in black-and-white: group boxes, option letters, empty tick boxes (CSS
    squares), "Recommended" and "Selected" as words, option prices outside the subtotal with a
    footnote. Internal notes and stock quantities are never printed.
    $mode: blank (form to tick by hand) | current (current choices) | selected (fixed + selected only).
--}}
@php
    $currency = $offer->currency;
    $m = fn (int $minor): string => $doc->money(\App\Support\Money::of($minor, $currency)->toMajor(), $currency);
    $amount = fn ($line): int => $offer->prices_include_vat ? $line->line_gross_minor : $line->line_net_minor;
    $hasGroups = $blocks->contains('type', 'group');
    $complete = $mode !== 'blank' && $offer->selection_complete;
    $showTotals = ! $hasGroups || $complete;
    $columns = ($showAccountantCode ? 8 : 7) + ($showImages ? 1 : 0);
    $included = $offer->lines->filter(fn ($line) => $line->option_group_id === null || ($complete && in_array($line->id, $selected, true)));
    $vatByRate = $included->groupBy('tax_rate_bp')->map(fn ($lines) => $lines->sum('line_vat_minor'))->sortKeys();
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-offer-document data-mode="{{ $mode }}">
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
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.offer.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $offer->number }}</span>@if ($offer->version > 1) · {{ __('documents.offer.version', ['n' => $offer->version]) }}@endif</p>
            <p>{{ __('documents.date') }}: {{ $doc->date($offer->sent_at ?? $offer->created_at) }}</p>
            <p>{{ __('documents.offer.valid_until') }}: <span class="font-semibold">{{ $doc->longDate($offer->valid_until) }}</span></p>
        </div>
    </header>

    <section class="grid grid-cols-2 gap-6">
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.seller') }}</p>
            <p class="font-semibold">{{ $seller['name'] }}</p>
            <p>{{ __('documents.prepared_by') }}: {{ $offer->owner?->name }}</p>
        </div>
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.buyer') }}</p>
            <p class="font-semibold">{{ $offer->customer?->legal_name ?? $offer->customer?->name }}</p>
            @if ($offer->customer?->address) <p>{{ __('documents.address') }}: {{ $offer->customer->address }}</p> @endif
            @if ($offer->customer?->tax_id) <p>{{ __('documents.tax_id') }}: {{ $offer->customer->tax_id }}</p> @endif
            @if ($offer->contact) <p>{{ __('documents.contact_person') }}: {{ $offer->contact->fullName() }}</p> @endif
        </div>
    </section>

    <p>{{ $offer->intro_text ?: __('documents.offer.intro') }}</p>

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
            @foreach ($blocks as $block)
                @if ($block['type'] === 'line' || $mode === 'selected')
                    @foreach ($block['type'] === 'line' ? [$block['line']] : $block['lines']->filter(fn ($line) => in_array($line->id, $selected, true)) as $line)
                        <tr class="print-avoid-break">
                            <td class="border border-black px-2 py-1.5 align-top">{{ $block['number'] }}{{ $line->option_label }}</td>
                            @if ($showImages)<td class="w-14 border border-black p-1 text-center align-top">@php($uri = $line->imageDataUri())@if ($uri)<img src="{{ $uri }}" alt="{{ $line->name }}" width="48" height="48" class="inline-block size-12 object-contain" data-print-image>@else<span class="inline-flex size-12 items-center justify-center border border-dashed border-black/40 text-[9px]" data-print-image-placeholder>{{ __('documents.offer.no_image') }}</span>@endif</td>@endif
                            <td class="border border-black px-2 py-1.5 align-top">
                                {{ $line->name }} <span class="text-[11px]">({{ $line->sku }})</span>
                                @if ($line->lead_time_days) <span class="block text-[11px]">{{ __('documents.offer.lead_time_days', ['days' => $line->lead_time_days]) }}</span> @endif
                            </td>
                            @if ($showAccountantCode)
                                <td class="border border-black px-2 py-1.5 align-top text-[11px]">{{ $line->accountant_code }}</td>
                            @endif
                            <td class="border border-black px-2 py-1.5 text-right align-top whitespace-nowrap">{{ $doc->quantity((string) $line->quantity) }}</td>
                            <td class="border border-black px-2 py-1.5 align-top">{{ $line->unit_name }}</td>
                            <td class="border border-black px-2 py-1.5 text-right align-top whitespace-nowrap">{{ $doc->number(\App\Support\Money::of($line->unit_price_minor, $currency)->toMajor()) }}</td>
                            <td class="border border-black px-2 py-1.5 text-right align-top whitespace-nowrap">{{ $line->discount_bp ? $doc->percent(bcdiv((string) $line->discount_bp, '100', 2)) : '—' }}</td>
                            <td class="border border-black px-2 py-1.5 text-right align-top whitespace-nowrap">{{ $doc->number(\App\Support\Money::of($amount($line), $currency)->toMajor()) }}</td>
                        </tr>
                    @endforeach
                @else
                    @php($group = $block['group'])
                    @php($isAlternative = $group->kind === \App\Enums\OptionGroupKind::Alternative)
                    <tr class="print-avoid-break">
                        <td colspan="{{ $columns }}" class="border border-black p-2">
                            <div @class(['p-2', 'border-4 border-double border-black' => $isAlternative, 'border-2 border-black' => ! $isAlternative]) data-print-group="{{ $group->kind->value }}">
                                <p class="font-bold uppercase">
                                    {{ $block['number'] }}.
                                    @if ($isAlternative)
                                        {{ $group->min_select === 1 && $group->max_select === 1 ? __('documents.offer.alternative_heading') : __('documents.offer.alternative_heading_range', ['min' => $group->min_select, 'max' => $group->max_select]) }}
                                    @else
                                        {{ __('documents.offer.optional_heading') }}
                                    @endif
                                </p>
                                <p class="font-semibold">{{ $group->title }}</p>
                                @if ($group->description) <p class="text-[11px]">{{ $group->description }}</p> @endif
                                <table class="mt-1 w-full border-collapse">
                                    <tbody>
                                        @foreach ($block['lines'] as $line)
                                            @php($isSelected = in_array($line->id, $selected, true))
                                            <tr class="print-avoid-break border-t border-black" data-print-option="{{ $block['number'] }}{{ $line->option_label }}">
                                                <td class="w-8 py-1 align-top">
                                                    <span class="inline-flex size-4 items-center justify-center border-2 border-black text-[11px] font-bold leading-none" data-tick-box>{{ $isSelected ? '✓' : '' }}</span>
                                                </td>
                                                @if ($showImages)
                                                    <td class="w-14 py-1 pr-2 align-top">@php($uri = $line->imageDataUri())@if ($uri)<img src="{{ $uri }}" alt="{{ $line->name }}" width="48" height="48" class="size-12 object-contain" data-print-image>@else<span class="inline-flex size-12 items-center justify-center border border-dashed border-black/40 text-[9px]" data-print-image-placeholder>{{ __('documents.offer.no_image') }}</span>@endif</td>
                                                @endif
                                                <td class="py-1 pr-2 align-top">
                                                    <span class="font-bold">{{ $block['number'] }}{{ $line->option_label }}</span>
                                                    {{ __('documents.offer.option', ['label' => $line->option_label]) }}:
                                                    {{ $line->name }} <span class="text-[11px]">({{ $line->sku }})</span>
                                                    @if ($line->is_recommended)
                                                        <span class="ml-1 border border-black px-1 text-[11px] font-semibold">★ {{ __('documents.offer.recommended') }}</span>
                                                    @endif
                                                    @if ($mode === 'current')
                                                        <span class="ml-1 text-[11px] font-semibold">{{ $isSelected ? __('documents.offer.selected') : __('documents.offer.not_selected') }}</span>
                                                    @endif
                                                    <span class="block text-[11px]">{{ $doc->quantity((string) $line->quantity) }} {{ $line->unit_name }} × {{ $doc->number(\App\Support\Money::of($line->unit_price_minor, $currency)->toMajor()) }}@if ($line->discount_bp) − {{ $doc->percent(bcdiv((string) $line->discount_bp, '100', 2)) }}@endif</span>
                                                    @if ($line->alternative_reason)
                                                        <span class="block text-[11px]">{{ $line->alternative_reason->label() }}@if ($line->alternative_reason_note): {{ $line->alternative_reason_note }}@endif</span>
                                                    @endif
                                                    @if ($line->comparison_notes)
                                                        <span class="block text-[11px] whitespace-pre-line">{{ $line->comparison_notes }}</span>
                                                    @endif
                                                    <span class="block text-[11px]">
                                                        @if ($line->stock_band) {{ $line->stock_band->label() }} @endif
                                                        @if ($line->lead_time_days) · {{ __('documents.offer.lead_time_days', ['days' => $line->lead_time_days]) }} @endif
                                                        @if ($showAccountantCode && $line->accountant_code) · {{ __('documents.offer.accountant_code') }}: {{ $line->accountant_code }} @endif
                                                    </span>
                                                </td>
                                                <td class="py-1 text-right align-top whitespace-nowrap">
                                                    <span class="block text-[10px] uppercase">{{ __('documents.offer.option_price') }}*</span>
                                                    <span class="font-normal">{{ $doc->number(\App\Support\Money::of($amount($line), $currency)->toMajor()) }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>

    @if ($hasGroups && $mode !== 'selected')
        <p class="text-[11px]" data-print-footnote>* {{ __('documents.offer.not_added_note') }}</p>
    @endif

    <section class="print-avoid-break ml-auto w-full max-w-md space-y-1" data-print-totals>
        @if ($hasGroups)
            <div class="flex justify-between gap-4"><span>{{ __('documents.offer.total_fixed') }}</span><span class="whitespace-nowrap">{{ $m($offer->fixed_gross_minor) }}</span></div>
        @endif
        @if ($showTotals)
            <div class="flex justify-between gap-4"><span>{{ __('documents.subtotal') }}</span><span class="whitespace-nowrap">{{ $m($offer->selected_net_minor) }}</span></div>
            @foreach ($vatByRate as $rate => $vat)
                <div class="flex justify-between gap-4">
                    <span>{{ (int) $rate === 0 ? __('documents.offer.vat_exempt') : __('documents.vat_rate', ['rate' => $doc->percent(bcdiv((string) $rate, '100', 2))]) }}</span>
                    <span class="whitespace-nowrap">{{ $m((int) $vat) }}</span>
                </div>
            @endforeach
            <div class="flex justify-between gap-4 border-t-2 border-black pt-1 font-bold">
                <span>{{ $hasGroups ? __('documents.offer.subtotal_selected') : __('documents.total_with_vat') }}</span>
                <span class="whitespace-nowrap" data-print-total>{{ $m($offer->selected_gross_minor) }}</span>
            </div>
            <p><span class="font-semibold">{{ __('documents.amount_in_words_label') }}:</span> <span data-amount-in-words>{{ $doc->amountInWords(\App\Support\Money::of($offer->selected_gross_minor, $currency)->toMajor(), $currency) }}</span></p>
        @else
            @if ($offer->range_min_gross_minor !== $offer->range_max_gross_minor)
                <p data-print-range>{{ __('documents.offer.price_range', ['min' => $m($offer->range_min_gross_minor), 'max' => $m($offer->range_max_gross_minor)]) }}</p>
            @endif
            @if ($offer->recommended_gross_minor !== null)
                <div class="flex justify-between gap-4"><span>{{ __('documents.offer.total_recommended') }}</span><span class="whitespace-nowrap">{{ $m($offer->recommended_gross_minor) }}</span></div>
            @endif
            <div class="flex items-end gap-2 pt-2 font-semibold">
                <span class="whitespace-nowrap">{{ __('documents.offer.subtotal_selected') }}:</span>
                <span class="flex-1 border-b border-black">&nbsp;</span>
            </div>
        @endif
    </section>

    @if ($hasGroups && $mode !== 'selected')
        <section class="print-avoid-break space-y-3 border-2 border-black p-3" data-customer-selection>
            <p class="font-bold uppercase">{{ __('documents.offer.customer_selection') }}</p>
            <p>{{ __('documents.offer.selection_instruction') }}</p>
            <div class="grid grid-cols-2 gap-x-8 gap-y-4">
                @foreach (['documents.offer.company', 'documents.full_name', 'documents.position', 'documents.signature', 'documents.date', 'documents.stamp'] as $labelKey)
                    <div class="flex items-end gap-2"><span class="whitespace-nowrap">{{ __($labelKey) }}:</span><span class="flex-1 border-b border-black">&nbsp;</span></div>
                @endforeach
            </div>
        </section>
    @endif

    <section class="print-avoid-break space-y-1">
        @if ($offer->payment_terms_text || $offer->payment_terms_days)
            <p><span class="font-semibold">{{ __('documents.payment_terms') }}:</span> {{ $offer->payment_terms_text }}@if ($offer->payment_terms_days) ({{ __('documents.offer.payment_days', ['days' => $offer->payment_terms_days]) }})@endif</p>
        @endif
        @if ($offer->delivery_terms_text) <p><span class="font-semibold">{{ __('documents.delivery_terms') }}:</span> {{ $offer->delivery_terms_text }}</p> @endif
        @if ($offer->warranty_text) <p><span class="font-semibold">{{ __('documents.warranty') }}:</span> {{ $offer->warranty_text }}</p> @endif
        @if ($offer->customer_notes) <p><span class="font-semibold">{{ __('documents.notes') }}:</span> {{ $offer->customer_notes }}</p> @endif
        <p>{{ __('documents.offer.acceptance') }}</p>
    </section>

    <footer class="print-avoid-break grid grid-cols-2 gap-10 pt-6">
        <div>
            <p class="text-xs font-semibold tracking-wide uppercase">{{ __('documents.prepared_by') }}</p>
            <p class="mt-1 font-semibold">{{ $offer->owner?->name }}</p>
            @if ($offer->owner?->position) <p>{{ __('documents.position') }}: {{ $offer->owner->position }}</p> @endif
        </div>
        <div class="space-y-6">
            <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.signature') }}:</span><span class="flex-1 border-b border-black"></span></div>
            <p>{{ __('documents.stamp') }}</p>
        </div>
    </footer>
</article>
