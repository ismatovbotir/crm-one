@props(['statuses' => []])

{{--
    BR-I.21 staleness alert: one line per 1C feed that has not been received within its threshold.
    $statuses comes from App\Models\IntegrationFeed::statuses() (computed in the Livewire component).
    Renders nothing when every feed is fresh or the inbound API is switched off.
--}}
@php($stale = array_filter($statuses, fn (array $status): bool => $status['is_stale']))
@if ($stale !== [])
    <div {{ $attributes->merge(['class' => 'rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-700']) }} role="alert" data-onec-stale>
        <p class="flex items-center gap-2 font-semibold"><x-icon name="warning" class="size-4" /> {{ __('integration.stale') }}</p>
        <ul class="mt-1 list-disc pl-6">
            @foreach ($stale as $status)
                <li wire:key="stale-{{ $status['feed']->value }}">
                    @if ($status['last_received_at'])
                        {{ __('integration.stale_feed', ['feed' => $status['feed']->label(), 'ago' => $status['last_received_at']->diffForHumans(), 'minutes' => $status['stale_minutes']]) }}
                    @else
                        {{ __('integration.stale_feed_never', ['feed' => $status['feed']->label()]) }}
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
