<div class="space-y-6" data-approvals>
    <p class="text-sm text-muted">{{ __('tenders.approvals.intro') }}</p>

    @foreach (['publications' => $this->publications, 'awards' => $this->awards] as $kind => $tenders)
        <section class="card" aria-labelledby="ap-{{ $kind }}">
            <h2 id="ap-{{ $kind }}" class="border-b border-line px-5 py-3 font-semibold text-ink">{{ __('tenders.approvals.'.$kind) }} <span class="text-muted">({{ $tenders->count() }})</span></h2>
            <ul class="divide-y divide-line">
                @forelse ($tenders as $tender)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm" wire:key="ap-{{ $kind }}-{{ $tender->id }}">
                        <span>
                            <a href="{{ $kind === 'awards' && auth()->user()->can('viewBids', $tender) ? route('tenders.comparison', $tender) : route('tenders.show', ['tender' => $tender, 'tab' => $kind === 'awards' ? 'evaluation' : 'overview']) }}" wire:navigate class="link font-mono">{{ $tender->number }}</a>
                            <span class="text-ink">{{ $tender->title }}</span>
                            <span class="block text-xs text-muted">{{ $tender->type->label() }} · {{ $tender->owner?->name }}</span>
                        </span>
                        @if ($kind === 'awards')
                            <span class="text-xs text-muted">{{ $tender->awards->where('status', \App\Enums\AwardStatus::Proposed)->map(fn ($a) => $a->counterparty?->name)->implode(', ') }}</span>
                        @endif
                    </li>
                @empty
                    <li class="px-5 py-4 text-sm text-muted">{{ __('tenders.approvals.empty') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
