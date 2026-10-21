{{-- Serials of one line: inline up to $inlineMax, otherwise ranges with a pointer to the annex. --}}
@php
    $ranges = app(\App\Services\Stock\SerialRangeFormatter::class)->ranges($serials);
@endphp
@if (count($serials) <= $inlineMax)
    <span class="block font-mono text-[11px]">{{ __('documents.serials.label') }}: {{ implode(', ', $serials) }}</span>
@else
    <span class="block text-[11px]">{{ __('documents.serials.label') }}:
        @foreach ($ranges as $range)
            <span class="font-mono">{{ $range['count'] > 1 ? __('documents.serials.range', ['from' => $range['from'], 'to' => $range['to'], 'count' => $range['count']]) : $range['from'] }}</span>@if (! $loop->last), @endif
        @endforeach
        · {{ __('documents.serials.see_annex', ['count' => count($serials)]) }}
    </span>
@endif
