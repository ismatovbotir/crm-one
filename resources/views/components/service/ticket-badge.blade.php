@props(['ticket'])
@php([$tone, $icon] = $ticket->status->badge())
<x-badge :tone="$tone" :icon="$icon" data-ticket-status="{{ $ticket->status->value }}">{{ $ticket->status->label() }}</x-badge>
