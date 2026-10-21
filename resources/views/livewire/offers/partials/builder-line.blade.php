{{-- One editable offer line (fixed line or option of a group). Inputs save on blur (server-side recalculation). --}}
@php($isAlternativeOption = $inGroup && $group?->kind === \App\Enums\OptionGroupKind::Alternative)
<div wire:key="line-{{ $line->id }}" @class(['rounded-md border border-line bg-surface p-3', 'card' => ! $inGroup]) data-builder-line="{{ $label }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex min-w-0 gap-3">
        <x-item-thumb :url="$line->imageUrl()" :alt="$line->name" :size="56" />
        <div class="min-w-0">
            <p class="text-sm">
                <span class="font-bold text-ink">{{ $label }}</span>
                <span class="font-medium text-ink">{{ $line->name }}</span>
                <span class="font-mono text-xs text-muted">{{ $line->sku }}</span>
            </p>
            <div class="mt-1 flex flex-wrap gap-1 text-xs">
                @if ($line->stock_band)
                    <x-badge tone="outline" :icon="$line->stock_band->icon()">{{ $line->stock_band->label() }}</x-badge>
                @endif
                @if ($line->accountant_code)
                    <x-badge tone="outline">{{ __('catalog.items.fields.accountant_code') }}: {{ $line->accountant_code }}</x-badge>
                @endif
                @if ($line->is_recommended)
                    <x-badge tone="dark" icon="star">{{ __('documents.offer.recommended') }}</x-badge>
                @endif
                @if ($line->effective_discount_bp > 0)
                    <x-badge tone="silver">{{ __('offers.builder.effective_discount', ['discount' => (new \App\Documents\DocumentFormatter(\App\Enums\Locale::current()))->percent(bcdiv((string) $line->effective_discount_bp, '100', 2))]) }}</x-badge>
                @endif
            </div>
            @if ($line->cost_price_minor !== null)
                <p class="mt-1 flex flex-wrap items-center gap-1 text-xs text-muted" data-line-cost>
                    <x-icon name="lock" class="size-3.5" />
                    {{ __('offers.builder.line_cost', ['cost' => $offer->money($line->cost_price_minor)->format(), 'margin' => $offer->money($line->line_net_minor - (int) $line->costNetMinor())->format()]) }}
                    @if ($line->isBelowCost())
                        <x-badge tone="red" icon="warning">{{ __('offers.builder.below_cost_badge') }}</x-badge>
                    @endif
                </p>
            @endif
            @if ($line->is_serialized)
                <p class="mt-1 flex items-center gap-1 text-xs text-ink" data-serial-note><x-icon name="lock" class="size-3.5" /> {{ __('offers.builder.serial_note') }}</p>
            @endif
        </div>
        </div>
        <div class="flex flex-wrap gap-1">
            @if ($inGroup)
                <button type="button" wire:click="moveOption({{ $line->id }}, -1)" class="btn btn-secondary px-2 py-1" @disabled($isFirst) aria-label="{{ __('offers.builder.move_up') }}">&uarr;</button>
                <button type="button" wire:click="moveOption({{ $line->id }}, 1)" class="btn btn-secondary px-2 py-1" @disabled($isLast) aria-label="{{ __('offers.builder.move_down') }}">&darr;</button>
                @if ($isAlternativeOption)
                    <button type="button" wire:click="setRecommended({{ $line->id }})" class="btn btn-secondary px-3 py-1" aria-pressed="{{ $line->is_recommended ? 'true' : 'false' }}" data-recommend>{{ $line->is_recommended ? __('offers.builder.unrecommend') : __('offers.builder.recommend') }}</button>
                @endif
            @else
                <button type="button" wire:click="moveBlock('line', {{ $line->id }}, -1)" class="btn btn-secondary px-2 py-1" @disabled($isFirst) aria-label="{{ __('offers.builder.move_up') }}">&uarr;</button>
                <button type="button" wire:click="moveBlock('line', {{ $line->id }}, 1)" class="btn btn-secondary px-2 py-1" @disabled($isLast) aria-label="{{ __('offers.builder.move_down') }}">&darr;</button>
                <button type="button" wire:click="convertToAlternatives({{ $line->id }})" class="btn btn-secondary px-3 py-1" data-offer-alternatives>{{ __('offers.builder.offer_alternatives') }}</button>
            @endif
            <button type="button" wire:click="removeLine({{ $line->id }})" wire:confirm="{{ __('offers.builder.remove_line_confirm') }}" class="btn btn-danger-outline px-3 py-1">{{ __('offers.builder.remove') }}</button>
        </div>
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <div>
            <label for="qty-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.quantity') }} ({{ $line->unit_name }})</label>
            <input wire:model.blur="lineInputs.{{ $line->id }}.quantity" id="qty-{{ $line->id }}" type="text" inputmode="decimal" class="form-input tabular-nums">
            @error('lineInputs.'.$line->id.'.quantity') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="price-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.unit_price') }} ({{ $offer->currency }})</label>
            <input wire:model.blur="lineInputs.{{ $line->id }}.unit_price" id="price-{{ $line->id }}" type="text" inputmode="decimal" class="form-input tabular-nums">
            @error('lineInputs.'.$line->id.'.unit_price') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="discount-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.discount_percent') }}</label>
            <input wire:model.blur="lineInputs.{{ $line->id }}.discount" id="discount-{{ $line->id }}" type="text" inputmode="decimal" class="form-input tabular-nums">
            @error('lineInputs.'.$line->id.'.discount') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="lead-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.lead_time_days') }}</label>
            <input wire:model.blur="lineInputs.{{ $line->id }}.lead_time_days" id="lead-{{ $line->id }}" type="number" min="0" class="form-input">
            @error('lineInputs.'.$line->id.'.lead_time_days') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="text-right">
            <p class="text-xs font-medium text-muted">{{ $inGroup ? __('documents.offer.option_price') : __('documents.amount') }}</p>
            <p class="mt-2 font-semibold tabular-nums text-ink" data-line-total>{{ $offer->money($offer->prices_include_vat ? $line->line_gross_minor : $line->line_net_minor)->format() }}</p>
        </div>
    </div>

    @if ($inGroup)
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
            @if ($isAlternativeOption)
                <div>
                    <label for="reason-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.alternative_reason') }}</label>
                    <select wire:model.blur="lineInputs.{{ $line->id }}.alternative_reason" id="reason-{{ $line->id }}" class="form-input">
                        <option value="">—</option>
                        @foreach ($reasons as $value => $reasonLabel)
                            <option value="{{ $value }}">{{ $reasonLabel }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="reason-note-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.alternative_reason_note') }}</label>
                    <input wire:model.blur="lineInputs.{{ $line->id }}.alternative_reason_note" id="reason-note-{{ $line->id }}" type="text" maxlength="255" class="form-input">
                </div>
            @endif
            <div class="sm:col-span-2">
                <label for="comparison-{{ $line->id }}" class="text-xs font-medium text-muted">{{ __('offers.fields.comparison_notes') }}</label>
                <textarea wire:model.blur="lineInputs.{{ $line->id }}.comparison_notes" id="comparison-{{ $line->id }}" rows="2" class="form-input"></textarea>
            </div>
        </div>
    @endif

    <div class="mt-3">
        <label for="internal-{{ $line->id }}" class="flex items-center gap-1 text-xs font-medium text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('offers.fields.internal_note') }}</label>
        <input wire:model.blur="lineInputs.{{ $line->id }}.internal_note" id="internal-{{ $line->id }}" type="text" class="form-input" placeholder="{{ __('offers.builder.internal_hint') }}">
    </div>
</div>
