@props(['tabs', 'active', 'property' => 'tab', 'prefix' => 'tab', 'label' => ''])
{{--
    Server-side tabs (templates/page-detail, document pages): role=tablist/tab/tabpanel,
    aria-selected and aria-controls with real panel ids (inactive panels are empty and hidden, so a
    heavy tab loads only when opened), roving tabindex and Left/Right/Home/End keys via the CSP-safe
    Alpine `tabList` component, visible focus, horizontal scroll on small screens. Clicking sets the
    Livewire property (keep it #[Url] so links/reloads work); unsaved wire:model values travel with
    that request, so switching tabs never loses edits.

    $tabs: key => label, or key => ['label' => string, 'count' => ?int, 'marker' => ?string]
    (count: a counter pill after the label; marker: a short warning such as "unsaved changes").
--}}
<div {{ $attributes->merge(['data-tabs' => $prefix]) }}>
    <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ $label }}" class="flex gap-1 overflow-x-auto border-b border-line">
        @foreach ($tabs as $key => $definition)
            @php
                $text = is_array($definition) ? $definition['label'] : $definition;
                $count = is_array($definition) ? ($definition['count'] ?? null) : null;
                $marker = is_array($definition) ? ($definition['marker'] ?? null) : null;
            @endphp
            <button type="button" role="tab" id="{{ $prefix }}-{{ $key }}" aria-controls="{{ $prefix }}-panel-{{ $key }}" aria-selected="{{ $active === $key ? 'true' : 'false' }}" tabindex="{{ $active === $key ? '0' : '-1' }}"
                    wire:key="{{ $prefix }}-tab-{{ $key }}" wire:click="$set('{{ $property }}', '{{ $key }}')" data-tab="{{ $key }}"
                    @class(['-mb-px inline-flex items-center gap-1.5 border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600', 'border-brand-600 text-ink' => $active === $key, 'border-transparent text-muted hover:text-ink' => $active !== $key])>
                <span>{{ $text }}</span>
                @if ($count !== null)
                    <span class="rounded-full bg-neutral-100 px-1.5 text-xs font-medium text-ink tabular-nums" data-tab-count>{{ $count }}</span>
                @endif
                @if ($marker !== null && $marker !== '')
                    <span class="size-2 rounded-full bg-brand-600" aria-hidden="true"></span><span class="sr-only" data-tab-marker>{{ $marker }}</span>
                @endif
            </button>
        @endforeach
    </div>
    @foreach ($tabs as $key => $definition)
        @if ($key !== $active)
            <div role="tabpanel" id="{{ $prefix }}-panel-{{ $key }}" aria-labelledby="{{ $prefix }}-{{ $key }}" hidden></div>
        @endif
    @endforeach
    <div role="tabpanel" id="{{ $prefix }}-panel-{{ $active }}" aria-labelledby="{{ $prefix }}-{{ $active }}" tabindex="0" wire:loading.class="opacity-60" wire:target="{{ $property }}" class="mt-4 transition-opacity focus-visible:outline-2 focus-visible:outline-brand-600" data-tab-panel="{{ $active }}">
        {{ $slot }}
    </div>
</div>
