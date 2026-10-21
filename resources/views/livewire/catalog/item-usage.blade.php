<div class="divide-y divide-line" data-item-usage>
    @foreach (['offers' => $this->offers, 'orders' => $this->orders, 'leads' => $this->leads] as $kind => $records)
        @if ($records !== null)
            <section class="px-6 py-4" aria-labelledby="usage-{{ $kind }}" wire:key="usage-{{ $kind }}" data-usage="{{ $kind }}">
                <h4 id="usage-{{ $kind }}" class="font-semibold text-ink">{{ __('catalog.usage.'.$kind) }} ({{ $records->total() }})</h4>
                @if ($records->isEmpty())
                    <p class="mt-1 text-sm text-muted">{{ __('catalog.usage.none') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($records as $record)
                            <li class="flex flex-wrap justify-between gap-2 py-2" wire:key="u-{{ $kind }}-{{ $record->id }}">
                                <span>
                                    <a href="{{ route($kind.'.show', $record) }}" wire:navigate class="link font-mono">{{ $kind === 'offers' ? $record->displayNumber() : $record->number }}</a>
                                    <span class="text-muted">· {{ $record->customer?->name ?? ($kind === 'leads' ? $record->companyLabel() : '—') }}</span>
                                </span>
                                <span class="text-muted">{{ $record->status->label() }} · {{ $record->created_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-2">{{ $records->links() }}</div>
                @endif
            </section>
        @endif
    @endforeach
</div>
