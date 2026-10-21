@props(['tone' => 'silver', 'icon' => null])

{{--
    Status pill. Meaning is never colour alone: always pass a text label (and optionally an icon).
    Tones use theme tokens: red = attention/primary, dark = settled/positive, silver = neutral, outline = draft/secondary.
--}}
@php
    $tones = [
        'red' => 'bg-brand-600 text-white',
        'dark' => 'bg-ink text-white',
        'silver' => 'bg-sidebar text-ink',
        'outline' => 'border border-line-strong bg-surface text-ink',
        'danger-outline' => 'border border-brand-600 bg-surface text-brand-700',
    ];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap '.($tones[$tone] ?? $tones['silver'])]) }}>
    @if ($icon) <x-icon :name="$icon" class="size-3.5" /> @endif
    {{ $slot }}
</span>
