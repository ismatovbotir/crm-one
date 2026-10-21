@props(['status'])

{{-- 1C sync status of a customer (Spec 02 section 12): label + icon, never colour alone. --}}
@php
    $value = $status instanceof \App\Enums\SyncStatus ? $status : \App\Enums\SyncStatus::tryFrom((string) $status);
    [$tone, $icon] = match ($value) {
        \App\Enums\SyncStatus::Synced => ['silver', 'check'],
        \App\Enums\SyncStatus::Pending => ['silver', 'sync'],
        \App\Enums\SyncStatus::Failed => ['red', 'warning'],
        \App\Enums\SyncStatus::Conflict => ['danger-outline', 'warning'],
        default => ['outline', null],
    };
@endphp
@if ($value)
    <x-badge :tone="$tone" :icon="$icon">{{ $value->label() }}</x-badge>
@endif
