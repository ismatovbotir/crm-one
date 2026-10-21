@props([
    'offer',
    'blocks',
    'selected' => [],
    'totals',
    'interactive' => false,
])

{{--
    The offer exactly as the customer sees it (Spec 05 10, DEC-5): fixed lines, boxed
    "ALTERNATIVE — PLEASE CHOOSE ONE" and "OPTIONAL — TICK TO INCLUDE" groups with option
    letters, "Recommended" as text, the not-added footnote and a price range while choices are
    pending. Internal notes, internal line notes and stock quantities are never rendered.
    Labels come from documents.offer.* (same wording as the printed offer).
    $totals: fixed, min, max, recommended (nullable), selected, selected_net, selected_vat, complete.
--}}
@php
    $amount = fn ($line) => $offer->prices_include_vat ? $line->line_gross_minor : $line->line_net_minor;
    $money = fn (?int $minor) => $minor === null ? '—' : $offer->money($minor)->format();
    $formatter = new \App\Documents\DocumentFormatter(\App\Enums\Locale::current());
@endphp
<div {{ $attributes->merge(['class' => 'space-y-5 text-sm']) }} data-customer-view>
    @if ($offer->intro_text)
        <p class="whitespace-pre-line text-ink">{{ $offer->intro_text }}</p>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <tr class="bg-sidebar text-left text-xs font-semibold text-sidebar-text">
                    <th scope="col" class="px-3 py-2">{{ __('documents.line_no') }}</th>
                    <th scope="col" class="px-3 py-2">{{ __('documents.item') }}</th>
                    <th scope="col" class="px-3 py-2 text-right">{{ __('documents.qty') }}</th>
                    <th scope="col" class="px-3 py-2 text-right">{{ __('documents.unit_price') }}</th>
                    <th scope="col" class="px-3 py-2 text-right">{{ __('documents.discount') }}</th>
                    <th scope="col" class="px-3 py-2 text-right">{{ __('documents.amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($blocks as $block)
                    @if ($block['type'] === 'line')
                        @php($line = $block['line'])
                        <tr class="border-b border-line" wire:key="cv-line-{{ $line->id }}">
                            <td class="px-3 py-2 align-top">{{ $block['number'] }}</td>
                            <td class="px-3 py-2 align-top">
                                <div class="flex gap-3">
                                <x-item-thumb :url="$line->imageUrl()" :alt="$line->name" :size="48" />
                                <div class="min-w-0">
                                <span class="font-medium text-ink">{{ $line->name }}</span>
                                <span class="block font-mono text-xs text-muted">{{ $line->sku }}</span>
                                @if ($line->stock_band)
                                    <span class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted"><x-icon :name="$line->stock_band->icon()" class="size-3.5" /> {{ $line->stock_band->label() }}</span>
                                @endif
                                @if ($line->lead_time_days)
                                    <span class="block text-xs text-muted">{{ __('documents.offer.lead_time_days', ['days' => $line->lead_time_days]) }}</span>
                                @endif
                                </div>
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap">{{ $formatter->quantity((string) $line->quantity) }} {{ $line->unit_name }}</td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap tabular-nums">{{ $money($line->unit_price_minor) }}</td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap">{{ $line->discount_bp ? $formatter->percent(bcdiv((string) $line->discount_bp, '100', 2)) : '—' }}</td>
                            <td class="px-3 py-2 text-right align-top font-medium whitespace-nowrap tabular-nums">{{ $money($amount($line)) }}</td>
                        </tr>
                    @else
                        @php($group = $block['group'])
                        @php($isAlternative = $group->kind === \App\Enums\OptionGroupKind::Alternative)
                        <tr wire:key="cv-group-{{ $group->id }}">
                            <td colspan="6" class="py-3">
                                <section @class(['rounded-md p-3', 'border-4 border-double border-ink' => $isAlternative, 'border-2 border-line-strong' => ! $isAlternative]) aria-labelledby="cv-group-title-{{ $group->id }}" data-group-kind="{{ $group->kind->value }}">
                                    <p class="text-xs font-bold tracking-wide text-ink uppercase" data-group-heading>
                                        {{ $block['number'] }}.
                                        @if ($isAlternative)
                                            {{ $group->min_select === 1 && $group->max_select === 1 ? __('documents.offer.alternative_heading') : __('documents.offer.alternative_heading_range', ['min' => $group->min_select, 'max' => $group->max_select]) }}
                                        @else
                                            {{ __('documents.offer.optional_heading') }}
                                        @endif
                                    </p>
                                    <h4 id="cv-group-title-{{ $group->id }}" class="mt-1 font-semibold text-ink">{{ $group->title }}</h4>
                                    @if ($group->description)
                                        <p class="text-xs text-muted">{{ $group->description }}</p>
                                    @endif
                                    <ul class="mt-2 divide-y divide-line">
                                        @foreach ($block['lines'] as $line)
                                            @php($isSelected = in_array($line->id, $selected, true))
                                            <li class="flex flex-col gap-2 py-2 sm:flex-row sm:items-start sm:justify-between" wire:key="cv-option-{{ $line->id }}" data-option="{{ $block['number'] }}{{ $line->option_label }}">
                                                <div class="flex min-w-0 gap-3">
                                                    @if ($interactive)
                                                        <input
                                                            type="{{ $isAlternative && $group->max_select === 1 ? 'radio' : 'checkbox' }}"
                                                            @if ($isAlternative && $group->max_select === 1)
                                                                wire:model.live="previewSelection.{{ $group->id }}" value="{{ $line->id }}" name="preview-group-{{ $group->id }}"
                                                            @else
                                                                wire:model.live="previewSelection.{{ $group->id }}" value="{{ $line->id }}"
                                                            @endif
                                                            id="preview-{{ $line->id }}" class="mt-1 size-4 accent-brand-600"
                                                        >
                                                    @else
                                                        <span class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center border border-ink text-[10px] font-bold" aria-hidden="true">{{ $isSelected ? '✓' : '' }}</span>
                                                    @endif
                                                    <x-item-thumb :url="$line->imageUrl()" :alt="$line->name" :size="56" />
                                                    <label for="preview-{{ $line->id }}" class="min-w-0">
                                                        <span class="font-bold text-ink">{{ $block['number'] }}{{ $line->option_label }}</span>
                                                        <span class="font-medium text-ink">{{ $line->name }}</span>
                                                        @if ($line->is_recommended)
                                                            <span class="ml-1 inline-flex items-center gap-0.5 rounded-full border border-ink px-1.5 text-xs font-semibold text-ink">★ {{ __('documents.offer.recommended') }}</span>
                                                        @endif
                                                        @if (! $interactive)
                                                            <span class="ml-1 text-xs font-semibold {{ $isSelected ? 'text-ink' : 'text-muted' }}">{{ $isSelected ? __('documents.offer.selected') : __('documents.offer.not_selected') }}</span>
                                                        @endif
                                                        <span class="block font-mono text-xs text-muted">{{ $line->sku }} · {{ $formatter->quantity((string) $line->quantity) }} {{ $line->unit_name }} × {{ $money($line->unit_price_minor) }}</span>
                                                        @if ($line->alternative_reason)
                                                            <span class="block text-xs text-ink">{{ $line->alternative_reason->label() }}@if ($line->alternative_reason_note): {{ $line->alternative_reason_note }}@endif</span>
                                                        @endif
                                                        @if ($line->comparison_notes)
                                                            <span class="block text-xs whitespace-pre-line text-muted">{{ $line->comparison_notes }}</span>
                                                        @endif
                                                        <span class="flex flex-wrap gap-3 text-xs text-muted">
                                                            @if ($line->stock_band)
                                                                <span class="inline-flex items-center gap-1"><x-icon :name="$line->stock_band->icon()" class="size-3.5" /> {{ $line->stock_band->label() }}</span>
                                                            @endif
                                                            @if ($line->lead_time_days)
                                                                <span>{{ __('documents.offer.lead_time_days', ['days' => $line->lead_time_days]) }}</span>
                                                            @endif
                                                        </span>
                                                    </label>
                                                </div>
                                                <div class="shrink-0 text-right">
                                                    <span class="block text-xs text-muted">{{ __('documents.offer.option_price') }}</span>
                                                    <span class="tabular-nums text-ink">{{ $money($amount($line)) }}</span>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    @if (collect($blocks)->contains('type', 'group'))
        <p class="text-xs text-muted" data-not-added-note>* {{ __('documents.offer.not_added_note') }}</p>
    @endif

    <dl class="ml-auto max-w-md space-y-1 rounded-md border border-line p-4" data-customer-totals>
        <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('documents.offer.total_fixed') }}</dt><dd class="tabular-nums text-ink">{{ $money($totals['fixed']) }}</dd></div>
        @if ($totals['complete'])
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('documents.subtotal') }}</dt><dd class="tabular-nums text-ink">{{ $money($totals['selected_net']) }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('documents.vat') }}</dt><dd class="tabular-nums text-ink">{{ $money($totals['selected_vat']) }}</dd></div>
            <div class="flex justify-between gap-4 border-t border-line pt-1 font-semibold"><dt class="text-ink">{{ __('documents.offer.subtotal_selected') }}</dt><dd class="tabular-nums text-ink" data-selected-total>{{ $money($totals['selected']) }}</dd></div>
        @endif
        @if ($totals['min'] !== $totals['max'])
            <div class="border-t border-line pt-1 text-ink" data-price-range>{{ __('documents.offer.price_range', ['min' => $money($totals['min']), 'max' => $money($totals['max'])]) }}</div>
        @endif
        @if ($totals['recommended'] !== null && ! $totals['complete'] && collect($blocks)->contains('type', 'group'))
            <div class="flex justify-between gap-4"><dt class="text-muted">{{ __('documents.offer.total_recommended') }}</dt><dd class="tabular-nums text-ink">{{ $money($totals['recommended']) }}</dd></div>
        @endif
    </dl>

    @if ($offer->payment_terms_text || $offer->delivery_terms_text || $offer->warranty_text || $offer->customer_notes)
        <dl class="space-y-1">
            @foreach (['payment_terms' => $offer->payment_terms_text, 'delivery_terms' => $offer->delivery_terms_text, 'warranty' => $offer->warranty_text, 'notes' => $offer->customer_notes] as $label => $text)
                @if ($text)
                    <div wire:key="cv-term-{{ $label }}"><dt class="inline font-semibold text-ink">{{ __('documents.'.$label) }}:</dt> <dd class="inline whitespace-pre-line text-ink">{{ $text }}</dd></div>
                @endif
            @endforeach
        </dl>
    @endif
</div>
