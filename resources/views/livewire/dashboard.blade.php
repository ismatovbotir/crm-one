<div class="space-y-6">
    <section class="card overflow-hidden">
        <div class="flex flex-col gap-4 border-l-4 border-brand-600 p-6 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-xl font-semibold text-ink">{{ __('dashboard.welcome', ['name' => auth()->user()->name]) }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('dashboard.intro') }}</p>
            </div>
            <span class="inline-flex w-fit items-center rounded-full bg-ink px-3 py-1 text-xs font-semibold text-white">{{ auth()->user()->role->label() }}</span>
        </div>
    </section>

    <x-onec-stale-alert :statuses="$integrationStatuses" />

    @forelse ($sections as $section)
        <section aria-labelledby="dash-{{ $section['key'] }}" data-dashboard-section="{{ $section['key'] }}">
            <h2 id="dash-{{ $section['key'] }}" class="mb-3 text-sm font-semibold tracking-wide text-muted uppercase">{{ __('dashboard.sections.'.$section['key']) }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($section['tiles'] as $tile)
                    <article wire:key="tile-{{ $section['key'] }}-{{ $loop->index }}" class="card flex flex-col p-5">
                        <p class="text-sm font-medium text-muted">{{ $tile['label'] }}</p>
                        <p @class(['mt-2 font-semibold text-ink tabular-nums', 'text-3xl' => mb_strlen($tile['value']) < 14, 'text-base' => mb_strlen($tile['value']) >= 14])>{{ $tile['value'] }}</p>
                        @if (! empty($tile['hint'])) <p class="mt-2 text-xs text-muted">{{ $tile['hint'] }}</p> @endif
                        @if (! empty($tile['url'])) <a href="{{ $tile['url'] }}" wire:navigate class="link mt-auto pt-3 text-sm">{{ __('dashboard.open') }}</a> @endif
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <x-empty-state :title="__('dashboard.nothing_for_role')" icon="dashboard" />
    @endforelse

    <section class="card p-6">
        <h2 class="text-base font-semibold text-ink">{{ __('dashboard.workflow') }}</h2>
        <ol class="mt-4 flex flex-wrap items-center gap-2 text-sm">
            @foreach (['lead', 'customer', 'offer', 'order', 'agreement', 'payment', 'packaging', 'delivery'] as $stage)
                <li wire:key="stage-{{ $stage }}" class="flex items-center gap-2">
                    <span class="rounded-md border border-line-strong bg-surface px-3 py-1.5 font-medium text-ink">{{ __('dashboard.stages.'.$stage) }}</span>
                    @unless ($loop->last)
                        <span class="text-brand-600" aria-hidden="true">&rarr;</span>
                    @endunless
                </li>
            @endforeach
        </ol>

        <div class="mt-6 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted">{{ __('dashboard.print_preview_help') }}</p>
            <a href="{{ route('print.sample') }}" class="btn btn-secondary shrink-0">
                <x-icon name="printer" class="size-4" /> {{ __('dashboard.print_preview') }}
            </a>
        </div>
    </section>
</div>
