@props(['status'])

{{-- Offer status (Spec 05 6.1): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-offer-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
