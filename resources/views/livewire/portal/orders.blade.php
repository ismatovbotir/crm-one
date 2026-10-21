<div class="card" data-portal-orders>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr><th scope="col" class="px-4 py-3">{{ __('portal.orders.number') }}</th><th scope="col" class="px-4 py-3">{{ __('common.status') }}</th><th scope="col" class="px-4 py-3 text-right">{{ __('portal.orders.total') }}</th><th scope="col" class="px-4 py-3">{{ __('portal.orders.promised') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($orders as $order)
                    <tr wire:key="por-{{ $order->id }}">
                        <td class="px-4 py-3"><a href="{{ route('portal.orders.show', $order) }}" wire:navigate class="link font-mono">{{ $order->number }}</a></td>
                        <td class="px-4 py-3"><x-orders.status-badge :status="$order->status" /></td>
                        <td class="px-4 py-3 text-right whitespace-nowrap text-ink">{{ $order->money($order->gross_minor)->format() }}</td>
                        <td class="px-4 py-3 text-ink">{{ $order->promised_delivery_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-0"><x-empty-state :title="__('portal.orders.empty')" icon="orders" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($orders->hasPages())<div class="border-t border-line p-4">{{ $orders->links() }}</div>@endif
</div>
