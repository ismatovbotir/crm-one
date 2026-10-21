<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('accounting.receivables.intro') }}</p>

    @foreach ($totals as $currency => $total)
        <div class="card p-4" data-receivables-total="{{ $currency }}" wire:key="tot-{{ $currency }}">
            <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('accounting.receivables.total_in', ['currency' => $currency]) }}</p>
            <div class="mt-2 grid gap-3 text-sm sm:grid-cols-3 lg:grid-cols-7">
                @foreach ($buckets as $bucket)
                    <div><span class="block text-xs text-muted">{{ __('accounting.receivables.buckets.'.$bucket) }}</span><span class="font-semibold text-ink">{{ \App\Support\Money::of($total['buckets'][$bucket], $currency)->format() }}</span></div>
                @endforeach
                <div><span class="block text-xs text-muted">{{ __('accounting.receivables.total') }}</span><span class="font-semibold text-ink">{{ \App\Support\Money::of($total['total'], $currency)->format() }}</span></div>
                <div><span class="block text-xs text-muted">{{ __('accounting.receivables.advance') }}</span><span class="font-semibold text-ink">−{{ \App\Support\Money::of($total['advance'], $currency)->format() }}</span></div>
            </div>
        </div>
    @endforeach

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm" data-receivables>
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('accounting.fields.customer') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('accounting.fields.currency') }}</th>
                    @foreach ($buckets as $bucket)
                        <th scope="col" class="px-4 py-3 text-right">{{ __('accounting.receivables.buckets.'.$bucket) }}</th>
                    @endforeach
                    <th scope="col" class="px-4 py-3 text-right">{{ __('accounting.receivables.total') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('accounting.receivables.advance') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('accounting.receivables.reminder') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($this->rows as $row)
                    <tr wire:key="rc-{{ $row['customer_id'] }}-{{ $row['currency'] }}">
                        <td class="px-4 py-3"><a href="{{ route('customers.show', ['customer' => $row['customer_id'], 'tab' => 'finance']) }}" wire:navigate class="link">{{ $row['customer'] }}</a></td>
                        <td class="px-4 py-3">{{ $row['currency'] }}</td>
                        @foreach ($buckets as $bucket)
                            <td @class(['px-4 py-3 text-right whitespace-nowrap', 'font-semibold text-brand-700' => $bucket !== 'current' && $row['buckets'][$bucket] > 0])>{{ $row['buckets'][$bucket] > 0 ? \App\Support\Money::of($row['buckets'][$bucket], $row['currency'])->format() : '—' }}</td>
                        @endforeach
                        <td class="px-4 py-3 text-right font-semibold whitespace-nowrap">{{ \App\Support\Money::of($row['total'], $row['currency'])->format() }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ $row['advance'] > 0 ? '−'.\App\Support\Money::of($row['advance'], $row['currency'])->format() : '—' }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">@if ($row['total'] > 0)<a href="{{ route('receivables.reminder.print', $row['customer_id']) }}" target="_blank" rel="noopener" class="link text-xs" data-action="print-reminder">{{ __('accounting.receivables.reminder') }}</a>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="p-0"><x-empty-state :title="__('accounting.receivables.empty')" icon="check" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
