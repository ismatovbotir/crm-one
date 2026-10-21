@props(['title', 'description' => null, 'icon' => 'items'])

{{-- Empty list / no results / no access state. Optional call-to-action in the slot. --}}
<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-10 text-center']) }}>
    <span class="mb-3 grid size-12 place-items-center rounded-full bg-sidebar text-ink" aria-hidden="true">
        <x-icon :name="$icon" class="size-6" />
    </span>
    <p class="text-base font-semibold text-ink">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-md text-sm text-muted">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
