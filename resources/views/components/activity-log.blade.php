@props(['entries'])

{{-- Change-log timeline for a record (activity_log rows, newest first). --}}
@if ($entries->isEmpty())
    <x-empty-state :title="__('activity.empty')" icon="clock" />
@else
    <ol {{ $attributes->merge(['class' => 'divide-y divide-line']) }}>
        @foreach ($entries as $entry)
            <li wire:key="activity-{{ $entry->id }}" class="px-5 py-3">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm text-ink">
                        <span class="font-semibold">{{ $entry->eventLabel() }}</span>
                        <span class="text-muted">· {{ $entry->causer?->name ?? __('activity.system') }}</span>
                    </p>
                    <time datetime="{{ $entry->created_at?->toIso8601String() }}" class="text-xs text-muted">{{ $entry->created_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</time>
                </div>
                @php($changes = $entry->changes())
                @if ($changes !== [])
                    <dl class="mt-2 grid gap-1 text-xs sm:grid-cols-[minmax(8rem,auto)_1fr]">
                        @foreach ($changes as $change)
                            <dt class="font-medium text-muted">{{ $change['field'] }}</dt>
                            <dd class="break-words text-ink">
                                @if ($change['old'] !== '')
                                    <span class="text-muted line-through">{{ $change['old'] }}</span>
                                    <span aria-hidden="true">&rarr;</span>
                                    <span class="sr-only">{{ __('activity.changed_to') }}</span>
                                @endif
                                {{ $change['new'] }}
                            </dd>
                        @endforeach
                    </dl>
                @endif
            </li>
        @endforeach
    </ol>
@endif
