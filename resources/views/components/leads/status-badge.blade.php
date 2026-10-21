@props(['status'])

{{-- Lead status (Spec 04 section 5): text label + icon, never colour alone. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-lead-status="{{ $status->value }}">{{ $status->label() }}</x-badge>
