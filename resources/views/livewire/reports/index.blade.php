<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('reports.intro') }}</p>

    <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-reports>
        @foreach ($reports as $report)
            <li class="card flex flex-col p-5" wire:key="report-{{ $report->key() }}">
                <h2 class="font-semibold text-ink">{{ __('reports.list.'.$report->key().'.title') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('reports.list.'.$report->key().'.description') }}</p>
                <a href="{{ route('reports.show', $report->key()) }}" wire:navigate class="btn btn-secondary btn-sm mt-auto self-start" data-report-link="{{ $report->key() }}">{{ __('reports.open') }}</a>
            </li>
        @endforeach
    </ul>
</div>
