<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('stock.reconciliation.intro') }}</p>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <label class="inline-flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" wire:model.live="showAll" class="form-checkbox" data-filter="show-all">
            {{ __('stock.reconciliation.show_all') }}
        </label>
        @if ($total > count($visibleRows))
            <p class="text-sm text-muted" role="status">{{ __('stock.reconciliation.truncated', ['shown' => count($visibleRows), 'total' => $total]) }}</p>
        @endif
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm" data-reconciliation>
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('stock.fields.item') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('stock.fields.warehouse') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('stock.reconciliation.crm') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('stock.reconciliation.onec') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('stock.reconciliation.lag') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('stock.reconciliation.discrepancy') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($visibleRows as $row)
                    @php($diff = (float) $row['discrepancy'])
                    <tr wire:key="rc-{{ $row['item']->id }}-{{ $row['warehouse_id'] }}">
                        <td class="px-4 py-3 text-ink">{{ $row['item']->name }} <span class="block font-mono text-xs text-muted">{{ $row['item']->sku }} @if ($row['item']->accountant_code) · {{ $row['item']->accountant_code }}@endif</span></td>
                        <td class="px-4 py-3 text-ink">{{ $row['warehouse'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['crm'] }}</td>
                        <td class="px-4 py-3 text-right">{{ \App\Support\Quantity::trimmed($row['onec']) }}</td>
                        <td class="px-4 py-3 text-right">{{ $row['lag'] }}</td>
                        <td @class(['px-4 py-3 text-right font-semibold', 'text-brand-700' => $diff != 0.0, 'text-ink' => $diff == 0.0])>
                            {{ \App\Support\Quantity::trimmed($row['discrepancy']) }}
                            @if ($diff < 0) <span class="block text-xs font-normal">{{ __('stock.reconciliation.missing') }}</span> @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-0"><x-empty-state :title="$showAll ? __('stock.reconciliation.empty') : __('stock.reconciliation.no_differences')" icon="check" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
