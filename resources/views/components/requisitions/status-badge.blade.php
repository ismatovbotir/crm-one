@props(['status', 'portal' => false])
{{-- Spec 10: text + icon; the customer sees "Sent to supplier" for `received`. --}}
@php([$tone, $icon] = $status->badge())
<x-badge :tone="$tone" :icon="$icon" data-requisition-status="{{ $status->value }}">{{ $portal && $status === \App\Enums\RequisitionStatus::Received ? __('requisitions.statuses_portal.received') : $status->label() }}</x-badge>
