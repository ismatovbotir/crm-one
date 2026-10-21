<div class="space-y-6">
    <div class="card p-4" role="search">
        <label for="global-search-page" class="sr-only">{{ __('search.label') }}</label>
        <input wire:model.live.debounce.300ms="q" id="global-search-page" type="search" class="form-input" placeholder="{{ __('search.placeholder') }}" autofocus>
    </div>

    @if ($tooShort)
        <p class="text-sm text-muted">{{ __('search.hint') }}</p>
    @elseif ($groups === [])
        <x-empty-state :title="__('search.empty', ['term' => $q])" icon="customers" />
    @else
        <div class="grid gap-6 lg:grid-cols-2" data-search-results aria-live="polite">
            @foreach ($groups as $group => $hits)
                <section class="card" aria-labelledby="sg-{{ $group }}" data-search-group="{{ $group }}">
                    <h2 id="sg-{{ $group }}" class="border-b border-line px-5 py-3 text-sm font-semibold tracking-wide text-muted uppercase">{{ __('search.groups.'.$group) }}</h2>
                    <ul class="divide-y divide-line">
                        @foreach ($hits as $hit)
                            <li wire:key="hit-{{ $group }}-{{ $loop->index }}">
                                <a href="{{ $hit['url'] }}" wire:navigate class="block px-5 py-3 hover:bg-brand-50/40">
                                    <span class="block font-medium text-ink">{{ $hit['title'] }}</span>
                                    @if ($hit['subtitle'] !== '') <span class="block text-xs text-muted">{{ $hit['subtitle'] }}</span> @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif
</div>
