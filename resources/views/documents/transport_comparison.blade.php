{{--
    Comparison report (Spec 08 9.1-9.2): executive summary per lot, normalised table, charge
    matrix, scorecard, sensitivity and audit (snapshot hash, FX). Labels from documents.* and
    tenders.*, numbers via $doc; flags and matrix as text codes (black-and-white safe).
--}}
@php
    $eval = $report['tender']['evaluation_currency'];
    $m = fn ($minor, $currency) => $minor === null ? '—' : $doc->money(\App\Support\Money::of((int) $minor, $currency)->toMajor(), $currency);
@endphp
<article class="space-y-5 text-[12px] leading-snug text-black" data-comparison-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-3">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            <p>{{ $report['tender']['number'] }} · {{ $report['tender']['title'] }}</p>
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.transport_comparison.title') }}</h1>
            <p>{{ $snapshot ? __('documents.transport_comparison.snapshot', ['version' => $snapshot->version]) : __('documents.transport_comparison.preview') }}</p>
            @if ($snapshot)<p class="font-mono text-[10px]">sha256 {{ $snapshot->payload_hash }}</p>@endif
        </div>
    </header>

    @foreach ($report['lots'] as $lot)
        @php($first = collect($lot['rows'])->firstWhere('bid_lot_id', $lot['recommendation']['system_rank1']))
        @php($proposed = collect($lot['rows'])->firstWhere('bid_lot_id', $lot['recommendation']['proposed']))
        <section class="space-y-2 break-inside-avoid">
            <h2 class="text-sm font-bold">{{ __('documents.tender_notice.lot', ['number' => $lot['number']]) }}: {{ $lot['title'] }}@if ($lot['route']) · {{ $lot['route'] }}@endif</h2>
            <table class="w-full border-collapse">
                <tbody>
                    <tr><th scope="row" class="w-1/3 border border-black px-2 py-1 text-left">{{ __('documents.transport_comparison.system_rank1') }}</th><td class="border border-black px-2 py-1">{{ $first['counterparty'] ?? '—' }} · {{ $m($first['landed_minor'] ?? null, $eval) }}</td></tr>
                    <tr><th scope="row" class="border border-black px-2 py-1 text-left">{{ __('documents.transport_comparison.proposed') }}</th><td class="border border-black px-2 py-1">{{ $proposed['counterparty'] ?? '—' }}@if ($lot['recommendation']['difference_minor']) · {{ __('documents.transport_comparison.difference', ['amount' => $m($lot['recommendation']['difference_minor'], $eval)]) }}@endif @if ($lot['recommendation']['justification']) · {{ $lot['recommendation']['justification'] }}@endif</td></tr>
                    <tr><th scope="row" class="border border-black px-2 py-1 text-left">{{ __('documents.transport_comparison.savings') }}</th><td class="border border-black px-2 py-1">{{ $lot['savings'] ? $m($lot['savings']['saving_uzs'], 'UZS') : __('documents.transport_comparison.no_baseline') }}</td></tr>
                    <tr><th scope="row" class="border border-black px-2 py-1 text-left">{{ __('documents.transport_comparison.decision_level') }}</th><td class="border border-black px-2 py-1">{{ collect($lot['decision']['levels'])->map(fn ($l) => __('tenders.levels.'.$l))->implode(' → ') ?: '—' }}</td></tr>
                </tbody>
            </table>

            <table class="w-full border-collapse">
                <thead>
                    <tr class="text-left text-[11px] font-semibold">
                        <th scope="col" class="border border-black px-1 py-1">#</th>
                        <th scope="col" class="border border-black px-1 py-1">{{ __('documents.transport_comparison.carrier') }}</th>
                        <th scope="col" class="border border-black px-1 py-1 text-right">{{ __('documents.transport_comparison.landed', ['currency' => $eval]) }}</th>
                        <th scope="col" class="border border-black px-1 py-1 text-right">{{ __('documents.transport_comparison.bid_total') }}</th>
                        <th scope="col" class="border border-black px-1 py-1 text-right">{{ __('documents.transport_comparison.per_kg') }}</th>
                        <th scope="col" class="border border-black px-1 py-1 text-right">{{ __('documents.transport_comparison.days') }}</th>
                        <th scope="col" class="border border-black px-1 py-1 text-right">{{ __('documents.transport_comparison.score') }}</th>
                        <th scope="col" class="border border-black px-1 py-1">{{ __('documents.transport_comparison.flags') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($lot['rows'] as $row)
                        <tr class="align-top">
                            <td class="border border-black px-1 py-1">{{ $row['rank'] ?? '—' }}</td>
                            <td class="border border-black px-1 py-1">{{ $row['counterparty'] }}@foreach ($row['knockouts'] as $reason)<br>{{ __('tenders.knockouts.'.$reason) }}@endforeach</td>
                            <td class="border border-black px-1 py-1 text-right whitespace-nowrap">{{ $m($row['landed_minor'], $eval) }}</td>
                            <td class="border border-black px-1 py-1 text-right whitespace-nowrap">{{ $m($row['total_minor'], $row['currency']) }}</td>
                            <td class="border border-black px-1 py-1 text-right whitespace-nowrap">{{ $m($row['per_kg_minor'], $eval) }}</td>
                            <td class="border border-black px-1 py-1 text-right">{{ $row['transit_days'] ?? $row['lead_time_days'] ?? '—' }}</td>
                            <td class="border border-black px-1 py-1 text-right">{{ $row['weighted'] ?? '—' }}</td>
                            <td class="border border-black px-1 py-1 font-mono">{{ implode(' ', $row['flags']) ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @php($codes = collect($lot['rows'])->flatMap(fn ($r) => array_keys($r['matrix']))->unique()->values())
            @if ($codes->isNotEmpty())
                <table class="w-full border-collapse">
                    <thead><tr class="text-[11px] font-semibold"><th scope="col" class="border border-black px-1 py-1 text-left">{{ __('documents.transport_comparison.charge') }}</th>@foreach ($lot['rows'] as $row)<th scope="col" class="border border-black px-1 py-1">{{ $row['counterparty'] }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($codes as $code)
                            <tr><td class="border border-black px-1 py-1">{{ trans()->has('tenders.charges.'.$code) ? __('tenders.charges.'.$code) : $code }}</td>@foreach ($lot['rows'] as $row)<td class="border border-black px-1 py-1 text-center font-mono">{{ $row['matrix'][$code] ?? '–' }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($lot['sensitivity'])
                <p>{{ __('documents.transport_comparison.sensitivity') }}: @foreach ($lot['sensitivity'] as $case){{ $case['currency'] }} {{ $case['delta'] > 0 ? '+' : '' }}{{ $case['delta'] }}% — {{ $case['rank_changes'] ? __('documents.transport_comparison.rank_changes') : __('documents.transport_comparison.rank_stable') }}@if (! $loop->last); @endif @endforeach</p>
            @endif
        </section>
    @endforeach

    <section class="break-inside-avoid text-[11px]">
        <p>{{ __('documents.transport_comparison.legend') }}</p>
        <p>{{ __('documents.transport_comparison.fx') }}: @forelse ($report['fx'] as $currency => $rate){{ $currency }} = {{ $rate['rate'] }} UZS ({{ $rate['date'] }})@if (! $loop->last); @endif @empty — @endforelse</p>
        <p>{{ __('documents.transport_comparison.weights') }}: @foreach ($report['weights'] as $weight){{ $weight['label'] }} {{ $doc->percent(bcdiv((string) $weight['weight_bp'], '100', 2)) }}@if (! $loop->last); @endif @endforeach</p>
    </section>
</article>
