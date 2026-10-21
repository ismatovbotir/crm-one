@props(['status'])

{{-- Agreement status (Spec 06 6.5): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-agreement-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
