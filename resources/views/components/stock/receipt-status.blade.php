@props(['status'])

{{-- Goods receipt status (Spec 06 26.4): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-receipt-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
