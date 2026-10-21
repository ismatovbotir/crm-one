@props(['lastReceivedAt' => null])

{{-- "Synced from 1C" badge + last received placeholder (Spec 02 section 12). The catalog is read-only. --}}
<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-sm text-muted']) }}>
    <x-badge tone="dark" icon="sync">{{ __('integration.badge.synced_from_1c') }}</x-badge>
    <span>
        {{ __('integration.last_received') }}:
        @if ($lastReceivedAt)
            <time datetime="{{ $lastReceivedAt->toIso8601String() }}" title="{{ $lastReceivedAt->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}" class="text-ink">{{ $lastReceivedAt->diffForHumans() }}</time>
        @else
            <span class="text-ink">{{ __('common.never') }}</span>
        @endif
    </span>
    <span class="flex items-center gap-1"><x-icon name="lock" class="size-3.5" /> {{ __('integration.read_only') }}</span>
</div>
