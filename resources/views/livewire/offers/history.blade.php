<div class="space-y-4" data-offer-other>
    @php
        $offer = $this->offer;
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
    @endphp

    <section class="card p-5" aria-labelledby="offer-related-heading" data-related>
        <h3 id="offer-related-heading" class="font-semibold text-ink">{{ __('common.document_tabs.related') }}</h3>
        <ul class="mt-3 divide-y divide-line text-sm" data-versions>
            @if ($offer->lead)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2" data-related-lead>
                    <span class="text-muted">{{ __('offers.show.from_lead') }}</span>
                    @can('view', $offer->lead)
                        <a href="{{ route('leads.show', $offer->lead) }}" wire:navigate class="link font-mono">{{ $offer->lead->number }}</a>
                    @else
                        <span class="font-mono">{{ $offer->lead->number }}</span>
                    @endcan
                </li>
            @endif
            @foreach ($this->versions as $version)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="v-{{ $version->id }}">
                    <a href="{{ route('offers.show', $version) }}" wire:navigate @class(['font-mono hover:underline', 'font-semibold text-ink' => $version->id === $offer->id, 'text-brand-700' => $version->id !== $offer->id])>{{ $version->displayNumber() }}</a>
                    <x-offers.status-badge :status="$version->status" />
                    <span class="text-muted">{{ $version->created_at?->translatedFormat($dateTimeFormat) }}</span>
                    <span class="text-ink"><x-offers.total :offer="$version" /></span>
                </li>
            @endforeach
            @if ($offer->order)
                <li class="flex flex-wrap items-center justify-between gap-3 py-2" data-related-order>
                    <span class="text-muted">{{ __('offers.show.related_order') }}</span>
                    @can('view', $offer->order)
                        <a href="{{ route('orders.show', $offer->order) }}" wire:navigate class="link font-mono">{{ $offer->order->number }}</a>
                    @else
                        <span class="font-mono">{{ $offer->order->number }}</span>
                    @endcan
                </li>
            @endif
        </ul>
    </section>

    <section class="card p-5" aria-labelledby="offer-approvals-heading">
        <h3 id="offer-approvals-heading" class="font-semibold text-ink">{{ __('offers.show.tab_approvals') }}</h3>
        <ul class="mt-3 divide-y divide-line" data-approvals>
            @forelse ($this->approvals as $approval)
                <li class="py-3 text-sm" wire:key="ap-{{ $approval->id }}">
                    <p class="text-ink">
                        <span class="font-semibold">{{ $approval->status->label() }}</span>
                        · {{ __('offers.show.approval_line', ['required' => rtrim(rtrim(bcdiv((string) $approval->required_bp, '100', 2), '0'), '.'), 'limit' => rtrim(rtrim(bcdiv((string) $approval->owner_limit_bp, '100', 2), '0'), '.')]) }}
                    </p>
                    <p class="text-xs text-muted">{{ $approval->requester?->name }} · {{ $approval->requested_at->translatedFormat($dateTimeFormat) }}@if ($approval->decider) → {{ $approval->decider->name }} · {{ $approval->decided_at?->translatedFormat($dateTimeFormat) }}@endif</p>
                    @if ($approval->comment)
                        <p class="mt-1 text-xs text-ink">{{ $approval->comment }}</p>
                    @endif
                    @foreach ((array) $approval->reasons as $reason)
                        <p class="text-xs text-muted" wire:key="ap-{{ $approval->id }}-{{ $loop->index }}">{{ $reason['label'] }} {{ $reason['name'] }}: {{ rtrim(rtrim(bcdiv((string) $reason['discount_bp'], '100', 2), '0'), '.') }}%</p>
                    @endforeach
                </li>
            @empty
                <li class="py-3 text-sm text-muted">{{ __('offers.show.no_approvals') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card p-5" aria-labelledby="offer-history-heading">
        <h3 id="offer-history-heading" class="font-semibold text-ink">{{ __('common.document_tabs.history') }}</h3>
        <ol class="mt-3 divide-y divide-line" data-timeline>
            @forelse ($this->timeline as $item)
                @php
                    $model = $item['model'];
                @endphp
                <li class="py-3 text-sm" wire:key="tl-{{ $item['kind'] }}-{{ $model->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-ink">
                            @if ($item['kind'] === 'transition')
                                <span class="font-semibold">{{ $model->from_status ? __('offers.show.transition', ['from' => \App\Enums\OfferStatus::from($model->from_status)->label(), 'to' => \App\Enums\OfferStatus::from($model->to_status)->label()]) : __('offers.show.created_as', ['status' => \App\Enums\OfferStatus::from($model->to_status)->label()]) }}</span>
                                <span class="text-muted">· {{ $model->user?->name ?? __('activity.system') }}</span>
                            @elseif ($item['kind'] === 'selection')
                                <span class="font-semibold">{{ __('offers.selection_events.'.$model->event) }}</span>
                                @if ($model->line) <span>{{ $model->line->option_label }} {{ $model->line->name }}</span> @endif
                                <span class="text-muted">· {{ $model->user?->name ?? __('activity.system') }}@if ($model->channel) · {{ $model->channel->label() }}@endif</span>
                            @else
                                <span class="font-semibold">{{ $model->eventLabel() }}</span>
                                <span class="text-muted">· {{ $model->causer?->name ?? __('activity.system') }}</span>
                            @endif
                        </p>
                        <time datetime="{{ $item['at']->toIso8601String() }}" class="text-xs text-muted">{{ $item['at']->translatedFormat($dateTimeFormat) }}</time>
                    </div>
                    @if ($item['kind'] === 'transition' && $model->reasonLabel())
                        <p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $model->reasonLabel() }}</p>
                    @elseif ($item['kind'] === 'selection' && ($model->note || $model->evidence_note))
                        <p class="mt-1 text-xs text-muted">{{ collect([$model->note, $model->evidence_note])->filter()->join(' · ') }}</p>
                    @endif
                </li>
            @empty
                <li><x-empty-state :title="__('activity.empty')" icon="clock" /></li>
            @endforelse
        </ol>
    </section>
</div>
