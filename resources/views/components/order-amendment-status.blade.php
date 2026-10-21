@props(['status'])

{{-- Order amendment status (Spec 06 6.4): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-amendment-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
