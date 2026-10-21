@props(['receipt'])

{{-- Goods receipt progress (Spec 06 26.4): main path as numbered steps; on hold / cancelled shown as a note. --}}
@php
    $steps = \App\Enums\GoodsReceiptStatus::steps($receipt->isFromOneC());
    $current = $receipt->status;
    $off = in_array($current, [\App\Enums\GoodsReceiptStatus::OnHold, \App\Enums\GoodsReceiptStatus::Cancelled], true);
    $reached = $off ? -1 : array_search($current, $steps, true);
@endphp
<nav aria-label="{{ __('stock.receipts.progress') }}" {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2 text-sm']) }} data-receipt-stepper>
    <ol class="flex flex-wrap items-center gap-2">
        @foreach ($steps as $index => $step)
            @php($state = $reached === false || $reached < 0 ? 'todo' : ($index < $reached ? 'done' : ($index === $reached ? 'current' : 'todo')))
            <li class="flex items-center gap-2" @if ($state === 'current') aria-current="step" @endif data-step="{{ $step->value }}" data-state="{{ $state }}">
                <span @class([
                    'inline-flex size-6 items-center justify-center rounded-full border text-xs font-semibold',
                    'border-brand-700 bg-brand-700 text-white' => $state === 'current',
                    'border-ink bg-ink text-white' => $state === 'done',
                    'border-line text-muted' => $state === 'todo',
                ])>@if ($state === 'done')<x-icon name="check" class="size-3" />@else{{ $index + 1 }}@endif</span>
                <span @class(['font-semibold text-ink' => $state === 'current', 'text-ink' => $state === 'done', 'text-muted' => $state === 'todo'])>{{ $step->label() }}</span>
                @if (! $loop->last)<span class="text-muted" aria-hidden="true">&rarr;</span>@endif
            </li>
        @endforeach
    </ol>
    @if ($off)
        <x-stock.receipt-status :status="$current" />
    @endif
</nav>
