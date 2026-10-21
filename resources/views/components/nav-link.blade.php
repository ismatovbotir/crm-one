@props(['href' => null, 'icon' => null, 'active' => false, 'disabled' => false, 'newWindow' => false])

@if ($disabled)
    <span
        {{ $attributes->merge(['class' => 'flex cursor-not-allowed items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-sidebar-muted']) }}
        aria-disabled="true"
        title="{{ __('common.coming_soon') }}"
    >
        @if ($icon) <x-icon :name="$icon" /> @endif
        <span class="flex-1">{{ $slot }}</span>
        <span class="rounded bg-sidebar-line px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-sidebar-text uppercase">{{ __('common.soon') }}</span>
    </span>
@else
    <a
        href="{{ $href }}"
        @if ($newWindow) target="_blank" rel="noopener" @else wire:navigate @endif
        @if ($active) aria-current="page" @endif
        {{ $attributes->class([
            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
            'bg-brand-600 text-white' => $active,
            'text-sidebar-text hover:bg-sidebar-line' => ! $active,
        ]) }}
    >
        @if ($icon) <x-icon :name="$icon" /> @endif
        <span class="flex-1">{{ $slot }}@if ($newWindow) <span class="sr-only">({{ __('common.opens_in_new_window') }})</span>@endif</span>
    </a>
@endif
