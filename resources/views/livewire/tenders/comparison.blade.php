<div class="space-y-6" data-comparison>
    @php
        $tender = $this->tender;
        $report = $this->report;
        $snapshot = $this->snapshot;
        $eval = $report['tender']['evaluation_currency'];
        $money = fn ($minor, $currency) => $minor === null ? '—' : \App\Support\Money::of((int) $minor, $currency)->format();
        $tabs = ['summary', 'table', 'matrix', 'scorecard', 'sensitivity', 'evidence', 'audit'];
    @endphp

    <a href="{{ route('tenders.show', ['tender' => $tender, 'tab' => 'evaluation']) }}" wire:navigate class="link text-sm">&larr; {{ $tender->number }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="font-mono text-sm text-muted">{{ $tender->number }}</p>
                <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ __('tenders.comparison.title') }}</h2>
                <p class="mt-1 text-sm text-ink">{{ $tender->title }}</p>
                <p class="mt-2 text-xs text-muted" data-snapshot-state>
                    @if ($snapshot)
                        {{ __('tenders.comparison.snapshot', ['version' => $snapshot->version, 'status' => __('tenders.comparison.statuses.'.$snapshot->status), 'hash' => \Illuminate\Support\Str::limit($snapshot->payload_hash, 16, '…')]) }}
                    @else
                        {{ __('tenders.comparison.preview') }}
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('tenders.comparison.print', ['tender' => $tender, 'snapshot' => $snapshot?->uuid]) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('tenders.comparison.print') }}</a>
                <a href="{{ route('tenders.comparison.csv', ['tender' => $tender, 'snapshot' => $snapshot?->uuid]) }}" class="btn btn-secondary" data-action="export-csv">{{ __('tenders.comparison.csv') }}</a>
            </div>
        </div>
    </header>

    @if ($this->decidable->isNotEmpty())
        <section class="card border-2 border-brand-600 p-5" aria-labelledby="decide-title" data-decision-panel>
            <h3 id="decide-title" class="font-semibold text-ink">{{ __('tenders.comparison.decision_title') }}</h3>
            <label class="mt-3 flex items-center gap-2 text-sm text-ink"><input wire:model="reviewed" type="checkbox" class="rounded border-line"> {{ __('tenders.comparison.reviewed') }}</label>
            @error('reviewed') <p class="form-error">{{ $message }}</p> @enderror
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div><label for="dc-comment" class="form-label">{{ __('tenders.comment') }}</label><input wire:model="comment" id="dc-comment" type="text" class="form-input"></div>
                @if ($stepUp)
                    <div><label for="dc-password" class="form-label">{{ __('tenders.comparison.password') }}</label><input wire:model="password" id="dc-password" type="password" autocomplete="current-password" class="form-input"></div>
                @endif
            </div>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($this->decidable as $award)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="da-{{ $award->id }}">
                        <span class="text-ink">{{ __('tenders.lot_short', ['number' => $award->lot->number]) }} → <span class="font-semibold">{{ $award->counterparty?->name }}</span> · {{ $money($award->landed_cost_minor, $award->evaluation_currency) }} · {{ __('tenders.award.rank', ['rank' => $award->rank_at_proposal ?? '—']) }}@if ($award->justification) · {{ $award->justification }}@endif</span>
                        <span class="flex gap-2">
                            <button type="button" wire:click="decide({{ $award->id }}, true)" class="btn btn-primary btn-sm" data-action="approve">{{ __('tenders.actions.approve') }}</button>
                            <button type="button" wire:click="decide({{ $award->id }}, false)" class="btn btn-secondary btn-sm" data-action="return">{{ __('tenders.comparison.return') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div>
        <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('tenders.comparison.title') }}" class="flex gap-1 overflow-x-auto border-b border-line">
            @foreach ($tabs as $key)
                <button type="button" role="tab" id="ctab-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" wire:key="ctab-{{ $key }}"
                        @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>{{ __('tenders.comparison.tabs.'.$key) }}</button>
            @endforeach
        </div>

        <section class="card mt-4 space-y-6 p-5" role="tabpanel" aria-labelledby="ctab-{{ $tab }}">
            @if ($tab === 'evidence')
                <table class="min-w-full divide-y divide-line text-sm" data-evidence-index>
                    <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><tr><th scope="col" class="py-2">{{ __('tenders.bids.counterparty') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.channel') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.received_via') }}</th><th scope="col" class="py-2">{{ __('tenders.comparison.entered_by') }}</th><th scope="col" class="py-2">{{ __('tenders.comparison.files') }}</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($report['evidence'] as $row)
                            <tr><td class="py-2 text-ink">{{ $row['counterparty'] }} v{{ $row['version'] }}<span class="block font-mono text-[11px] text-muted">{{ \Illuminate\Support\Str::limit((string) $row['payload_hash'], 16, '…') }}</span></td><td class="py-2 text-ink">{{ __('tenders.bid_channel.'.$row['channel']) }}</td><td class="py-2 text-ink">{{ $row['received_via'] ? __('tenders.vocab.received_via.'.$row['received_via']) : '—' }}</td><td class="py-2 text-ink">{{ $row['entered_by'] ?? '—' }}</td><td class="py-2 text-xs text-muted">@foreach ($row['files'] as $file){{ $file['name'] }} ({{ \Illuminate\Support\Str::limit($file['sha256'], 12, '…') }})@if (! $loop->last), @endif @endforeach</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif ($tab === 'audit')
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.hash') }}</dt><dd class="font-mono text-xs break-all text-ink">{{ $snapshot?->payload_hash ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.generated') }}</dt><dd class="text-ink">{{ $snapshot ? ($snapshot->payload['meta']['generated_by'] ?? '').' · '.$tender->localTime($snapshot->generated_at)?->format('Y-m-d H:i').' '.$tz : '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.fx') }}</dt><dd class="text-ink">@forelse ($report['fx'] as $currency => $rate){{ $currency }} = {{ $rate['rate'] }} UZS ({{ $rate['date'] }}, {{ $rate['source'] }})@if (! $loop->last); @endif @empty — @endforelse</dd></div>
                </dl>
                <h4 class="font-semibold text-ink">{{ __('tenders.comparison.versions') }}</h4>
                <ul class="divide-y divide-line text-sm">
                    @foreach ($history as $row)
                        <li class="flex justify-between gap-2 py-1" wire:key="h-{{ $row->id }}"><a href="{{ route('tenders.comparison', ['tender' => $tender, 'snapshot' => $row->uuid]) }}" wire:navigate class="link">v{{ $row->version }} · {{ __('tenders.comparison.statuses.'.$row->status) }}</a><span class="font-mono text-xs text-muted">{{ \Illuminate\Support\Str::limit($row->payload_hash, 16, '…') }}</span></li>
                    @endforeach
                </ul>
                <h4 class="font-semibold text-ink">{{ __('tenders.decisions_title') }}</h4>
                <ul class="divide-y divide-line text-sm">
                    @forelse ($tender->decisions()->with('decider')->where('subject', 'award')->get() as $decision)
                        <li class="py-1 text-ink" wire:key="dec-{{ $decision->id }}">{{ __('tenders.decision_line', ['subject' => __('tenders.decision_subjects.award'), 'level' => $decision->level->label(), 'decision' => __('tenders.decision_values.'.$decision->decision), 'user' => $decision->decider?->name]) }}@if ($decision->confirmed_via) · {{ __('tenders.comparison.confirmed_via.'.$decision->confirmed_via) }}@endif @if ($decision->comment) · {{ $decision->comment }}@endif</li>
                    @empty
                        <li class="py-1 text-muted">{{ __('tenders.no_decisions') }}</li>
                    @endforelse
                </ul>
            @else
                @foreach ($report['lots'] as $lot)
                    <div class="space-y-3" wire:key="clot-{{ $lot['lot_id'] }}-{{ $tab }}" data-comparison-lot="{{ $lot['number'] }}">
                        <h3 class="font-semibold text-ink">{{ __('tenders.lot_short', ['number' => $lot['number']]) }} · {{ $lot['title'] }}@if ($lot['route']) · {{ $lot['route'] }}@endif</h3>

                        @if ($tab === 'summary')
                            @php($first = collect($lot['rows'])->firstWhere('bid_lot_id', $lot['recommendation']['system_rank1']))
                            @php($proposed = collect($lot['rows'])->firstWhere('bid_lot_id', $lot['recommendation']['proposed']))
                            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                <div class="rounded-md border border-line p-3"><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.system_rank1') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $first['counterparty'] ?? '—' }}</dd><dd class="text-ink">{{ $money($first['landed_minor'] ?? null, $eval) }}</dd></div>
                                <div class="rounded-md border border-line p-3"><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.proposed') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $proposed['counterparty'] ?? '—' }}</dd>@if ($lot['recommendation']['difference_minor'])<dd class="text-ink">{{ __('tenders.comparison.difference', ['amount' => $money($lot['recommendation']['difference_minor'], $eval)]) }}</dd>@endif @if ($lot['recommendation']['justification'])<dd class="text-xs text-muted">{{ $lot['recommendation']['justification'] }}</dd>@endif</div>
                                <div class="rounded-md border border-line p-3"><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.savings') }}</dt><dd class="mt-1 text-ink">{{ $lot['savings'] ? $money($lot['savings']['saving_uzs'], 'UZS') : __('tenders.comparison.no_baseline') }}</dd></div>
                                <div class="rounded-md border border-line p-3"><dt class="text-xs font-medium text-muted uppercase">{{ __('tenders.comparison.decision_level') }}</dt><dd class="mt-1 text-ink">{{ collect($lot['decision']['levels'])->map(fn ($l) => \App\Enums\ApprovalLevel::from($l)->label())->implode(' → ') ?: '—' }}</dd>@foreach ($lot['decision']['reasons'] as $reason)<dd class="text-xs text-muted">{{ __('tenders.routing.reasons.'.$reason) }}</dd>@endforeach</div>
                            </dl>
                            <ol class="list-decimal space-y-1 pl-5 text-sm">
                                @foreach (array_slice(array_values(array_filter($lot['rows'], fn ($r) => $r['rank'] !== null)), 0, 3) as $row)
                                    <li class="text-ink">{{ $row['counterparty'] }} · {{ $money($row['landed_minor'], $eval) }} · {{ $row['transit_days'] !== null ? trans_choice('tenders.bids.days', $row['transit_days'], ['count' => $row['transit_days']]) : '' }} · {{ __('tenders.evaluation.weighted') }} {{ $row['weighted'] }} @if ($row['flags'])<span class="font-mono text-xs text-muted">[{{ implode(' ', $row['flags']) }}]</span>@endif</li>
                                @endforeach
                            </ol>
                        @elseif ($tab === 'table')
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-line text-sm" data-comparison-table>
                                    <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><tr><th scope="col" class="py-2">{{ __('tenders.evaluation.rank') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.counterparty') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.landed', ['currency' => $eval]) }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.bid_total') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.comparison.per_kg') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.bids.transit_days') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.valid_until') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.weighted') }}</th><th scope="col" class="py-2">{{ __('tenders.comparison.flags') }}</th></tr></thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach ($lot['rows'] as $row)
                                            <tr class="align-top">
                                                <td class="py-2 font-semibold text-ink">{{ $row['rank'] ?? '—' }}</td>
                                                <td class="py-2 text-ink">{{ $row['counterparty'] }}@foreach ($row['knockouts'] as $reason)<span class="block text-xs text-brand-800">{{ __('tenders.knockouts.'.$reason) }}</span>@endforeach</td>
                                                <td class="py-2 text-right whitespace-nowrap text-ink">{{ $money($row['landed_minor'], $eval) }}</td>
                                                <td class="py-2 text-right whitespace-nowrap text-ink">{{ $money($row['total_minor'], $row['currency']) }}</td>
                                                <td class="py-2 text-right whitespace-nowrap text-ink">{{ $money($row['per_kg_minor'], $eval) }}</td>
                                                <td class="py-2 text-right text-ink">{{ $row['transit_days'] ?? $row['lead_time_days'] ?? '—' }}</td>
                                                <td class="py-2 text-ink">{{ $row['valid_until'] ?? '—' }}</td>
                                                <td class="py-2 text-right font-semibold text-ink">{{ $row['weighted'] ?? '—' }}</td>
                                                <td class="py-2 font-mono text-xs text-ink" data-flags>{{ implode(' ', $row['flags']) ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-muted">{{ __('tenders.comparison.flags_legend') }}</p>
                        @elseif ($tab === 'matrix')
                            @php($codes = collect($lot['rows'])->flatMap(fn ($r) => array_keys($r['matrix']))->unique()->values())
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-line text-sm" data-matrix>
                                    <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><tr><th scope="col" class="py-2">{{ __('tenders.bids.charge') }}</th>@foreach ($lot['rows'] as $row)<th scope="col" class="py-2 text-center">{{ $row['counterparty'] }}</th>@endforeach</tr></thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach ($codes as $code)
                                            <tr><td class="py-1 text-ink">{{ trans()->has('tenders.charges.'.$code) ? __('tenders.charges.'.$code) : $code }}</td>@foreach ($lot['rows'] as $row)<td class="py-1 text-center font-mono text-ink">{{ $row['matrix'][$code] ?? '–' }}</td>@endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-xs text-muted">{{ __('tenders.comparison.matrix_legend') }}</p>
                        @elseif ($tab === 'scorecard')
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-line text-sm" data-scorecard-table>
                                    <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><tr><th scope="col" class="py-2">{{ __('tenders.bids.counterparty') }}</th>@foreach ($report['weights'] as $weight)<th scope="col" class="py-2 text-right">{{ $weight['label'] }} {{ bcdiv((string) $weight['weight_bp'], '100', 0) }}%</th>@endforeach<th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.weighted') }}</th></tr></thead>
                                    <tbody class="divide-y divide-line">
                                        @foreach ($lot['rows'] as $row)
                                            <tr><td class="py-1 text-ink">{{ $row['counterparty'] }}</td>@foreach ($report['weights'] as $weight)<td class="py-1 text-right text-ink">{{ $row['scores'][$weight['code']] ?? '—' }}</td>@endforeach<td class="py-1 text-right font-semibold text-ink">{{ $row['weighted'] ?? '—' }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <ul class="space-y-1 text-sm" data-sensitivity>
                                @forelse ($lot['sensitivity'] as $case)
                                    <li class="text-ink">{{ $case['currency'] }} {{ $case['delta'] > 0 ? '+' : '' }}{{ $case['delta'] }}%: {{ $case['rank_changes'] ? __('tenders.comparison.rank_changes') : __('tenders.comparison.rank_stable') }}</li>
                                @empty
                                    <li class="text-muted">{{ __('tenders.comparison.no_sensitivity') }}</li>
                                @endforelse
                            </ul>
                        @endif
                    </div>
                @endforeach
            @endif
        </section>
    </div>
</div>
