@props(['url' => null, 'alt' => '', 'size' => 40])

{{-- Item picture thumbnail (Spec 01 section 15) or a neutral placeholder; never stretched. --}}
@if ($url)
    <img src="{{ $url }}" alt="{{ $alt }}" width="{{ $size }}" height="{{ $size }}" loading="lazy" style="width: {{ $size }}px; height: {{ $size }}px" {{ $attributes->merge(['class' => 'shrink-0 rounded-md border border-line bg-surface object-contain']) }} data-item-thumb>
@else
    <span style="width: {{ $size }}px; height: {{ $size }}px" {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center rounded-md border border-dashed border-line bg-neutral-50 text-muted']) }} data-item-thumb-placeholder>
        <x-icon name="items" class="size-1/2" />
        <span class="sr-only">{{ __('catalog.images.none') }}</span>
    </span>
@endif
