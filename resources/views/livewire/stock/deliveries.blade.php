<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('stock.deliveries.intro') }}</p>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('stock.deliveries.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('orders.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.warehouse') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->deliveries as $delivery)
                        <tr wire:key="dn-{{ $delivery->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap"><a href="{{ route('delivery-notes.show', $delivery) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $delivery->number }}</a><span class="block font-mono text-xs text-muted">{{ $delivery->order?->number }}</span></td>
                            <td class="px-4 py-3 text-ink">{{ $delivery->order?->customer?->name }}</td>
                            <td class="px-4 py-3">@php([$tone, $icon] = $delivery->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $delivery->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-ink">{{ $delivery->warehouse?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-0"><x-empty-state :title="__('stock.deliveries.empty')" icon="orders" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->deliveries->hasPages())
            <div class="border-t border-line p-4">{{ $this->deliveries->links() }}</div>
        @endif
    </div>
</div>
