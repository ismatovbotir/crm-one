@props(['sidebar' => false, 'size' => 40, 'wordmark' => true])

{{-- Company logo tile (square, fixed size, never stretched) with an optional "CRM" wordmark. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-3']) }}>
    <img
        src="{{ \App\Models\Setting::logoUrl() }}"
        alt="RSG"
        width="{{ $size }}"
        height="{{ $size }}"
        style="width: {{ $size }}px; height: {{ $size }}px"
        class="shrink-0 rounded-lg bg-surface object-contain shadow-sm ring-1 ring-ink/10"
    >
    @if ($wordmark)
        <span class="text-lg font-semibold tracking-tight {{ $sidebar ? 'text-sidebar-text' : 'text-ink' }}">{{ __('common.brand') }}</span>
    @endif
</span>
