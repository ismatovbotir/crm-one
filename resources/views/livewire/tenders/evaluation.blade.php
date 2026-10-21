<div class="space-y-8" data-evaluation>
    @php
        $tender = $this->tender;
        $user = auth()->user();
        $eval = $tender->evaluation_currency;
        $money = fn (?int $minor, string $currency) => $minor === null ? '—' : \App\Support\Money::of($minor, $currency)->format();
        $manualCriteria = $tender->criteria->where('method', \App\Enums\CriterionMethod::Manual);
        $canEvaluate = $user->can('evaluate', $tender);
        $canScore = $user->can('score', $tender);
    @endphp

    @if (! $tender->isOpened())
        <x-empty-state :title="__('tenders.evaluation.not_opened')" icon="lock" />
    @elseif (! $canSee)
        <x-empty-state :title="__('tenders.evaluation.no_access')" icon="lock" />
    @else
        <div class="flex justify-end"><a href="{{ route('tenders.comparison', $tender) }}" wire:navigate class="btn btn-secondary" data-action="open-comparison">{{ __('tenders.comparison.open') }}</a></div>

        <section aria-labelledby="fx-title" data-fx>
            <h3 id="fx-title" class="font-semibold text-ink">{{ __('tenders.evaluation.fx_title') }}</h3>
            <p class="mt-1 text-sm text-muted">{{ __('tenders.evaluation.fx_hint') }}</p>
            @if ($currencies === [])
                <p class="mt-2 text-sm text-muted">{{ __('tenders.evaluation.fx_not_needed') }}</p>
            @else
                <form wire:submit="saveFx" class="mt-3 flex flex-wrap items-end gap-3">
                    @foreach ($currencies as $currency)
                        <div wire:key="fx-{{ $currency }}">
                            <label for="fx-{{ $currency }}" class="form-label">{{ __('tenders.evaluation.rate_for', ['currency' => $currency]) }}</label>
                            <input wire:model="fx.{{ $currency }}" id="fx-{{ $currency }}" type="text" inputmode="decimal" class="form-input w-36" @cannot('setFx', $tender) disabled @endcannot>
                            @if ($tender->fx_rates[$currency] ?? null)<p class="mt-1 text-xs text-muted">{{ __('tenders.evaluation.rate_frozen', ['date' => $tender->fx_rates[$currency]['date']]) }}</p>@endif
                        </div>
                    @endforeach
                    @can('setFx', $tender)
                        <div><label for="fx-date" class="form-label">{{ __('tenders.evaluation.rate_date') }}</label><input wire:model="fxDate" id="fx-date" type="date" class="form-input"></div>
                        <button type="submit" class="btn btn-primary">{{ __('tenders.evaluation.freeze') }}</button>
                    @endcan
                </form>
            @endif
        </section>

        @if ($problems !== [])
            <ul class="space-y-1 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-problems>
                @foreach ($problems as $problem)
                    <li>{{ __('tenders.problems.'.$problem['key'], $problem['params']) }}</li>
                @endforeach
            </ul>
        @endif

        @foreach ($tender->lots as $lot)
            @php($rows = $this->bids->flatMap(fn ($bid) => $bid->lots->where('lot_id', $lot->id)->map(fn ($bidLot) => ['bid' => $bid, 'bidLot' => $bidLot]))->sortBy(fn ($row) => $row['bidLot']->rank ?? PHP_INT_MAX)->values())
            <section aria-labelledby="lot-{{ $lot->id }}-title" wire:key="eval-lot-{{ $lot->id }}" data-scorecard="{{ $lot->id }}">
                <h3 id="lot-{{ $lot->id }}-title" class="font-semibold text-ink">{{ __('tenders.lot_short', ['number' => $lot->number]) }} · {{ $lot->title }}</h3>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                            <tr>
                                <th scope="col" class="py-2">{{ __('tenders.evaluation.rank') }}</th>
                                <th scope="col" class="py-2">{{ __('tenders.bids.counterparty') }}</th>
                                <th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.bid_total') }}</th>
                                <th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.landed', ['currency' => $eval]) }}</th>
                                @foreach ($tender->criteria as $criterion)
                                    <th scope="col" class="py-2 text-right">{{ $criterion->displayLabel() }} <span class="font-normal">{{ bcdiv((string) $criterion->weight_bp, '100', 0) }}%</span></th>
                                @endforeach
                                <th scope="col" class="py-2 text-right">{{ __('tenders.evaluation.weighted') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @forelse ($rows as $row)
                                @php([$bid, $bidLot] = [$row['bid'], $row['bidLot']])
                                <tr wire:key="row-{{ $bidLot->id }}" class="align-top" data-rank="{{ $bidLot->rank }}">
                                    <td class="py-2 font-semibold text-ink">{{ $bidLot->rank ?? '—' }}</td>
                                    <td class="py-2 text-ink">{{ $bid->counterparty?->name }}
                                        @if ($bid->channel === \App\Enums\BidChannel::Employee)<x-badge tone="outline">F9</x-badge>@endif
                                        @if ($bidLot->uses_estimates)<x-badge tone="silver">{{ __('tenders.evaluation.estimate_flag') }}</x-badge>@endif
                                        @foreach ((array) $bidLot->knockout_reasons as $reason)<span class="block text-xs text-brand-800">{{ __('tenders.knockouts.'.$reason) }}</span>@endforeach
                                        @if ($canEvaluate)
                                            @if ($bid->status === \App\Enums\BidStatus::Disqualified)
                                                <button type="button" wire:click="requalify({{ $bid->id }})" class="link text-xs">{{ __('tenders.evaluation.requalify') }}</button>
                                            @elseif ($disqualifyBidId === $bid->id)
                                                <span class="mt-1 flex items-end gap-1"><label for="dq-{{ $bid->id }}" class="sr-only">{{ __('tenders.evaluation.disqualify_reason') }}</label><input wire:model="disqualifyReason" id="dq-{{ $bid->id }}" type="text" class="form-input" placeholder="{{ __('tenders.evaluation.disqualify_reason') }}"><button type="button" wire:click="disqualify" class="btn btn-danger-outline btn-sm">{{ __('tenders.evaluation.disqualify') }}</button></span>
                                            @else
                                                <button type="button" wire:click="$set('disqualifyBidId', {{ $bid->id }})" class="link block text-xs">{{ __('tenders.evaluation.disqualify') }}</button>
                                            @endif
                                        @endif
                                    </td>
                                    <td class="py-2 text-right whitespace-nowrap text-ink">{{ $money($bidLot->total_minor, $bid->currency) }}</td>
                                    <td class="py-2 text-right whitespace-nowrap text-ink">{{ $money($bidLot->landed_cost_minor, $eval) }}
                                        <details class="text-left text-xs text-muted"><summary class="cursor-pointer">{{ __('tenders.evaluation.breakdown') }}</summary>
                                            <ul>
                                                @foreach ((array) ($bidLot->breakdown['components'] ?? []) as $component)
                                                    <li>{{ \Illuminate\Support\Str::startsWith($component['code'], 'item:') ? __('tenders.evaluation.components.item') : (trans()->has('tenders.charges.'.$component['code']) ? __('tenders.charges.'.$component['code']) : __('tenders.evaluation.components.'.$component['kind'])) }}@if ($component['kind'] === 'estimate') ({{ __('tenders.evaluation.estimate_flag') }})@endif: {{ $money($component['amount'], $eval) }}</li>
                                                @endforeach
                                            </ul>
                                        </details>
                                        @foreach ($bidLot->lines->where('inclusion', \App\Enums\ChargeInclusion::NotIncluded) as $line)
                                            <span class="mt-1 flex items-center justify-end gap-1 text-xs" wire:key="est-{{ $line->id }}">
                                                <label for="est-{{ $line->id }}">{{ $line->charge_code?->label() }}</label>
                                                <input wire:model="estimates.{{ $line->id }}" id="est-{{ $line->id }}" type="text" inputmode="decimal" class="form-input w-28" @disabled(! $canEvaluate)>
                                                @if ($canEvaluate)<button type="button" wire:click="saveEstimate({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>@endif
                                            </span>
                                        @endforeach
                                    </td>
                                    @foreach ($tender->criteria as $criterion)
                                        <td class="py-2 text-right whitespace-nowrap text-ink">
                                            {{ $bidLot->breakdown['scores'][$criterion->code] ?? '—' }}
                                            @if ($criterion->method === \App\Enums\CriterionMethod::Manual && $canScore)
                                                <span class="mt-1 flex items-center justify-end gap-1">
                                                    <label for="sc-{{ $bidLot->id }}-{{ $criterion->id }}" class="sr-only">{{ __('tenders.evaluation.my_score') }}</label>
                                                    <input wire:model="scores.{{ $bidLot->id }}:{{ $criterion->id }}" id="sc-{{ $bidLot->id }}-{{ $criterion->id }}" type="text" inputmode="decimal" class="form-input w-16" placeholder="0-10">
                                                    <button type="button" wire:click="saveScore({{ $bidLot->id }}, {{ $criterion->id }})" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="py-2 text-right font-semibold text-ink">{{ $bidLot->score ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ 5 + $tender->criteria->count() }}" class="py-3 text-sm text-muted">{{ __('tenders.evaluation.no_bids') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @can('proposeAward', $tender)
                    @unless ($this->awards->contains(fn ($a) => $a->lot_id === $lot->id && in_array($a->status, [\App\Enums\AwardStatus::Approved, \App\Enums\AwardStatus::Accepted], true)))
                        <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_2fr]">
                            <div>
                                <label for="sel-{{ $lot->id }}" class="form-label">{{ __('tenders.award.select') }}</label>
                                <select wire:model="selection.{{ $lot->id }}.bid_lot_id" id="sel-{{ $lot->id }}" class="form-input">
                                    <option value="">—</option>
                                    @foreach ($rows->filter(fn ($row) => $row['bidLot']->rank !== null) as $row)
                                        <option value="{{ $row['bidLot']->id }}">#{{ $row['bidLot']->rank }} · {{ $row['bid']->counterparty?->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div><label for="just-{{ $lot->id }}" class="form-label">{{ __('tenders.award.justification') }}</label><input wire:model="selection.{{ $lot->id }}.justification" id="just-{{ $lot->id }}" type="text" class="form-input" placeholder="{{ __('tenders.award.justification_hint') }}"></div>
                        </div>
                    @endunless
                @endcan
            </section>
        @endforeach

        @can('proposeAward', $tender)
            <div class="flex justify-end"><button type="button" wire:click="propose" class="btn btn-primary" data-action="propose-award">{{ __('tenders.award.propose') }}</button></div>
        @endcan
    @endif

    @if ($this->awards->isNotEmpty())
        <section aria-labelledby="awards-title" data-awards>
            <h3 id="awards-title" class="font-semibold text-ink">{{ __('tenders.award.title') }}</h3>
            <ul class="mt-2 divide-y divide-line">
                @foreach ($this->awards as $award)
                    <li class="py-3 text-sm" wire:key="award-{{ $award->id }}" data-award="{{ $award->status->value }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-ink"><span class="font-semibold">{{ __('tenders.lot_short', ['number' => $award->lot->number]) }} → {{ $award->counterparty?->name }}</span>
                                · {{ $canSee ? $money($award->amount_minor, $award->currency).' · '.__('tenders.evaluation.landed', ['currency' => $award->evaluation_currency]).': '.$money($award->landed_cost_minor, $award->evaluation_currency) : '' }}
                                · {{ __('tenders.award.rank', ['rank' => $award->rank_at_proposal ?? '—']) }}</p>
                            @php([$tone, $icon] = $award->status->badge())
                            <x-badge :tone="$tone" :icon="$icon">{{ $award->status->label() }}</x-badge>
                        </div>
                        @if ($award->justification)<p class="text-xs text-muted">{{ __('tenders.award.justification') }}: {{ $award->justification }}</p>@endif
                        <p class="text-xs text-muted">{{ __('tenders.award.levels', ['levels' => collect($award->required_levels)->map(fn ($l) => \App\Enums\ApprovalLevel::from($l)->label())->implode(' → ')]) }}</p>
                        @foreach ($award->decisions as $decision)
                            <p class="text-xs text-muted">{{ __('tenders.decision_line', ['subject' => __('tenders.decision_subjects.award'), 'level' => $decision->level->label(), 'decision' => __('tenders.decision_values.'.$decision->decision), 'user' => $decision->decider?->name]) }}@if ($decision->comment) · {{ $decision->comment }}@endif</p>
                        @endforeach

                        @if ($award->status === $pendingAwardStatus && collect($award->pendingLevels())->contains(fn ($level) => $router->canDecide($user, $tender, $level, $award)) && in_array($tender->type->value, (array) config('tenders.decision_step_up'), true))
                            <a href="{{ route('tenders.comparison', $tender) }}" wire:navigate class="btn btn-primary btn-sm mt-2" data-action="decide-on-report">{{ __('tenders.comparison.decide_on_report') }}</a>
                        @elseif ($award->status === $pendingAwardStatus && collect($award->pendingLevels())->contains(fn ($level) => $router->canDecide($user, $tender, $level, $award)))
                            <div class="mt-2 flex flex-wrap items-end gap-2" data-decide>
                                <div class="min-w-64 flex-1"><label for="dc-{{ $award->id }}" class="sr-only">{{ __('tenders.comment') }}</label><input wire:model="decisionComments.{{ $award->id }}" id="dc-{{ $award->id }}" type="text" class="form-input" placeholder="{{ __('tenders.comment') }}"></div>
                                <button type="button" wire:click="decide({{ $award->id }}, true)" class="btn btn-primary btn-sm" data-action="approve-award">{{ __('tenders.actions.approve') }}</button>
                                <button type="button" wire:click="decide({{ $award->id }}, false)" class="btn btn-secondary btn-sm" data-action="reject-award">{{ __('tenders.actions.reject') }}</button>
                            </div>
                        @endif

                        @if ($award->status === \App\Enums\AwardStatus::Approved)
                            <p class="mt-1 text-xs text-muted">{{ __('tenders.award.accept_due', ['at' => $tender->localTime($award->accept_due_at)?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}</p>
                            @can('recordAcceptance', $tender)
                                <div class="mt-2 flex flex-wrap items-end gap-2" data-acceptance>
                                    <div class="min-w-64 flex-1"><label for="acc-{{ $award->id }}" class="sr-only">{{ __('tenders.award.acceptance_note') }}</label><input wire:model="acceptanceNotes.{{ $award->id }}" id="acc-{{ $award->id }}" type="text" class="form-input" placeholder="{{ __('tenders.award.acceptance_note') }}"></div>
                                    <button type="button" wire:click="recordAcceptance({{ $award->id }}, true)" class="btn btn-primary btn-sm">{{ __('tenders.award.accepted') }}</button>
                                    <button type="button" wire:click="recordAcceptance({{ $award->id }}, false)" class="btn btn-secondary btn-sm">{{ __('tenders.award.declined') }}</button>
                                </div>
                            @endcan
                        @endif

                        @if ($award->purchaseOrder)
                            <p class="mt-1 text-sm">
                                @can('view', $award->purchaseOrder)
                                    <a href="{{ route('purchase-orders.show', $award->purchaseOrder) }}" wire:navigate class="link font-mono">{{ $award->purchaseOrder->number }}</a>
                                @else
                                    <span class="font-mono">{{ $award->purchaseOrder->number }}</span>
                                @endcan
                            </p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
