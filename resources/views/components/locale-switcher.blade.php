@props(['align' => 'right'])

@php
    $current = \App\Enums\Locale::current();
@endphp

{{-- Interface language dropdown (UZ | RU | EN). Posts to locale.update for this session; signed-in users can also make the current language their default. --}}
<div x-data="{ open: false }" class="relative" @click.outside="open = false" @keydown.escape="open = false" data-locale-switcher>
    <button
        type="button"
        class="flex items-center gap-1 rounded-md border border-line px-2 py-1.5 text-xs font-semibold text-ink hover:bg-neutral-100"
        @click="open = !open"
        aria-haspopup="menu"
        aria-expanded="false"
        :aria-expanded="open.toString()"
        aria-label="{{ __('common.change_language') }}: {{ $current->label() }}"
    >
        <x-icon name="language" class="size-4 text-muted" />
        {{ $current->shortCode() }}
        <x-icon name="chevron-down" class="size-3.5 text-muted" />
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition
        @class([
            'absolute z-30 mt-2 w-44 overflow-hidden rounded-md border border-line bg-surface py-1 shadow-lg',
            'right-0' => $align === 'right',
            'left-0' => $align !== 'right',
        ])
        role="menu"
    >
        @foreach (\App\Enums\Locale::available() as $locale)
            <form method="POST" action="{{ route('locale.update') }}">
                @csrf
                <input type="hidden" name="locale" value="{{ $locale->value }}">
                <button
                    type="submit"
                    role="menuitemradio"
                    aria-checked="{{ $locale === $current ? 'true' : 'false' }}"
                    lang="{{ $locale->value }}"
                    @class([
                        'flex w-full items-center gap-3 px-4 py-2 text-left text-sm hover:bg-brand-50 hover:text-brand-700',
                        'font-semibold text-brand-700' => $locale === $current,
                        'text-ink' => $locale !== $current,
                    ])
                >
                    <span class="w-6 text-xs font-semibold text-muted">{{ $locale->shortCode() }}</span>
                    <span class="flex-1">{{ $locale->label() }}</span>
                </button>
            </form>
        @endforeach
        @auth
            @if (auth()->user()->locale !== $current)
                <form method="POST" action="{{ route('locale.update') }}" class="border-t border-line">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $current->value }}">
                    <input type="hidden" name="remember" value="1">
                    <button type="submit" class="w-full px-4 py-2 text-left text-xs text-muted hover:bg-brand-50 hover:text-brand-700" data-action="remember-locale">{{ __('common.remember_language', ['language' => $current->label()]) }}</button>
                </form>
            @endif
        @endauth
    </div>
</div>
