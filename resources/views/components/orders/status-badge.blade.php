@props(['status'])

{{-- Order status (Spec 06 6.1): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-order-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
