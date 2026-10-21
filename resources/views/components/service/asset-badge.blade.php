@props(['asset'])
{{-- Spec 09: status with text and icon (never colour alone). --}}
@php([$tone, $icon] = $asset->status->badge())
<span class="inline-flex flex-wrap gap-1" data-asset-status="{{ $asset->status->value }}">
    <x-badge :tone="$tone" :icon="$icon">{{ $asset->status->label() }}</x-badge>
</span>
