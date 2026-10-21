@props(['status'])

{{-- Stock-off act status (Spec 06 27.3): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-stock-off-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
