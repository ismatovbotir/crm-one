<div class="space-y-6">
    @php($dateFormat = \App\Enums\Locale::current()->dateFormat())

    <a href="{{ route('reports.index') }}" wire:navigate class="link text-sm">&larr; {{ __('reports.back') }}</a>

    <p class="text-sm text-muted">{{ __('reports.list.'.$report->key().'.description') }}</p>

    <div class="card">
        <div class="flex flex-wrap items-end gap-3 border-b border-line p-4" role="search">
            @if ($report->usesDateRange())
                <div>
                    <label for="report-from" class="form-label">{{ __('reports.from') }}</label>
                    <input wire:model.live="from" id="report-from" type="date" class="form-input">
                    @error('from') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="report-to" class="form-label">{{ __('reports.to') }}</label>
                    <input wire:model.live="to" id="report-to" type="date" class="form-input">
                    @error('to') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @else
                <p class="text-sm text-muted">{{ __('reports.point_in_time') }}</p>
            @endif
            <div class="ml-auto flex gap-2">
                <a href="{{ route('reports.export', ['report' => $report->key(), 'format' => 'csv', ...$exportQuery]) }}" class="btn btn-secondary btn-sm" data-export="csv">{{ __('reports.export_csv') }}</a>
                <a href="{{ route('reports.export', ['report' => $report->key(), 'format' => 'xlsx', ...$exportQuery]) }}" class="btn btn-secondary btn-sm" data-export="xlsx">{{ __('reports.export_xlsx') }}</a>
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full divide-y divide-line text-sm" data-report-table="{{ $report->key() }}">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col" @class(['px-4 py-3 whitespace-nowrap', 'text-right' => in_array($column['type'], ['int', 'money', 'percent'], true)])>{{ __('reports.columns.'.$column['key']) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($rows as $index => $row)
                        <tr wire:key="row-{{ $index }}">
                            @foreach ($columns as $column)
                                @php($value = $row[$column['key']] ?? null)
                                <td @class(['px-4 py-2 whitespace-nowrap text-ink', 'text-right' => in_array($column['type'], ['int', 'money', 'percent'], true)])>
                                    @if ($value === null || $value === '')
                                        —
                                    @elseif ($column['type'] === 'money')
                                        {{ \App\Support\Money::of((int) $value['minor'], (string) $value['currency'])->format() }}
                                    @elseif ($column['type'] === 'percent')
                                        {{ rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') }} %
                                    @elseif ($column['type'] === 'date')
                                        {{ \Carbon\CarbonImmutable::parse($value)->translatedFormat($dateFormat) }}
                                    @else
                                        {{ $value }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) }}" class="p-0"><x-empty-state :title="__('reports.empty')" icon="receivables" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($more)
            <p class="border-t border-line p-4 text-sm text-muted">{{ __('reports.preview_limited', ['count' => \App\Livewire\Reports\Show::PREVIEW_ROWS]) }}</p>
        @endif
    </div>
</div>
