{{-- Status history, approval decisions (append-only) and the change log. --}}
<div class="space-y-8">
    <section aria-labelledby="hist-title">
        <h3 id="hist-title" class="font-semibold text-ink">{{ __('tenders.history_title') }}</h3>
        <ol class="mt-2 divide-y divide-line" data-transitions>
            @foreach ($tender->transitions()->with('user')->get() as $transition)
                <li class="py-2 text-sm" wire:key="tr-{{ $transition->id }}">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-ink"><span class="font-semibold">{{ $transition->from_status ? __('tenders.transition', ['from' => \App\Enums\TenderStatus::from($transition->from_status)->label(), 'to' => \App\Enums\TenderStatus::from($transition->to_status)->label()]) : __('tenders.created_as', ['status' => \App\Enums\TenderStatus::from($transition->to_status)->label()]) }}</span> <span class="text-muted">· {{ $transition->user?->name ?? __('activity.system') }}</span></p>
                        <time datetime="{{ $transition->created_at->toIso8601String() }}" class="text-xs text-muted">{{ $local($transition->created_at) }} {{ $tz }}</time>
                    </div>
                    @if ($transition->reasonLabel())<p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $transition->reasonLabel() }}</p>@endif
                </li>
            @endforeach
        </ol>
    </section>

    <section aria-labelledby="dec-title">
        <h3 id="dec-title" class="font-semibold text-ink">{{ __('tenders.decisions_title') }}</h3>
        <ul class="mt-2 divide-y divide-line" data-decisions>
            @forelse ($tender->decisions()->with('decider')->get() as $decision)
                <li class="py-2 text-sm" wire:key="dc-{{ $decision->id }}">
                    <p class="text-ink">{{ __('tenders.decision_line', ['subject' => __('tenders.decision_subjects.'.$decision->subject), 'level' => $decision->level->label(), 'decision' => __('tenders.decision_values.'.$decision->decision), 'user' => $decision->decider?->name]) }}</p>
                    <p class="text-xs text-muted">{{ $local($decision->created_at) }} {{ $tz }}@if ($decision->comment) · {{ $decision->comment }}@endif</p>
                </li>
            @empty
                <li class="py-2 text-sm text-muted">{{ __('tenders.no_decisions') }}</li>
            @endforelse
        </ul>
    </section>

    <section aria-labelledby="act-title">
        <h3 id="act-title" class="font-semibold text-ink">{{ __('tenders.activity_title') }}</h3>
        <x-activity-log :entries="$tender->activities()->with('causer')->limit(100)->get()" class="mt-2" />
    </section>
</div>
