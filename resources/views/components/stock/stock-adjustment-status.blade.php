@props(['status'])

{{-- Stock adjustment status (Spec 06 21.4): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-stock-adjustment-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
