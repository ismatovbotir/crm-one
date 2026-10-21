@props(['steps', 'current', 'furthest' => null, 'method' => 'goToStep', 'label' => ''])
{{--
    Numbered step indicator of a create wizard. $steps: key => label (in order); $current: 1-based
    step; $furthest: highest step reached (steps up to it are buttons that call $method(number),
    later ones are plain text so nobody skips ahead without "Next").
--}}
@php
    $furthest = max($furthest ?? $current, $current);
@endphp
<nav aria-label="{{ $label }}" {{ $attributes }} data-wizard-steps>
    <ol class="flex flex-wrap items-center gap-2 text-sm">
        @foreach (array_values($steps) as $index => $text)
            @php
                $number = $index + 1;
                $state = $number < $current ? 'done' : ($number === $current ? 'current' : 'todo');
                $key = array_keys($steps)[$index];
            @endphp
            <li class="flex items-center gap-2" @if ($state === 'current') aria-current="step" @endif data-step="{{ $key }}" data-state="{{ $state }}" wire:key="wizard-step-{{ $key }}">
                @if ($number !== $current && $number <= $furthest)
                    <button type="button" wire:click="{{ $method }}({{ $number }})" class="flex items-center gap-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                @else
                    <span class="flex items-center gap-2">
                @endif
                    <span @class([
                        'inline-flex size-6 items-center justify-center rounded-full border text-xs font-semibold',
                        'border-brand-700 bg-brand-700 text-white' => $state === 'current',
                        'border-ink bg-ink text-white' => $state === 'done',
                        'border-line text-muted' => $state === 'todo',
                    ])>@if ($state === 'done')<x-icon name="check" class="size-3" />@else{{ $number }}@endif</span>
                    <span @class(['font-semibold text-ink' => $state === 'current', 'text-ink' => $state === 'done', 'text-muted' => $state === 'todo'])>{{ $text }}</span>
                @if ($number !== $current && $number <= $furthest)
                    </button>
                @else
                    </span>
                @endif
                @if (! $loop->last)<span class="text-muted" aria-hidden="true">&rarr;</span>@endif
            </li>
        @endforeach
    </ol>
</nav>
