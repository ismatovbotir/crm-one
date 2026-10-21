<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('stock.serials.intro') }}</p>
        <livewire:stock.serial-registration />
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr_1fr]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('stock.serials.search_placeholder') }}" class="form-input font-mono" aria-label="{{ __('common.search') }}" data-serial-search>
            <select wire:model.live="item" class="form-input" aria-label="{{ __('stock.fields.item') }}" data-item-filter>
                <option value="">{{ __('stock.serials.all_items') }}</option>
                @foreach ($items as $option)
                    <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->sku }})</option>
                @endforeach
            </select>
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('stock.serials.active_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.serial') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.item') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.warehouse') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.serials.received') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.serials.document') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->serials as $serial)
                        <tr wire:key="sn-{{ $serial->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3"><a href="{{ route('serials.show', $serial) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $serial->serial_normalized }}</a></td>
                            <td class="px-4 py-3 text-ink">{{ $serial->item?->name }} <span class="block font-mono text-xs text-muted">{{ $serial->item?->sku }}</span></td>
                            <td class="px-4 py-3">@php([$tone, $icon] = $serial->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $serial->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-ink">{{ $serial->warehouse?->name ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink">{{ $serial->received_at?->setTimezone((string) config('orders.timezone'))->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                            <td class="px-4 py-3">
                                @if ($receipt = $serial->receiptLine?->receipt)
                                    @can('view', $receipt)
                                        <a href="{{ route('goods-receipts.show', $receipt) }}" wire:navigate class="link font-mono">{{ $receipt->number }}</a>
                                    @else
                                        <span class="font-mono">{{ $receipt->number }}</span>
                                    @endcan
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-0"><x-empty-state :title="__('stock.serials.empty')" icon="items" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->serials->hasPages())
            <div class="border-t border-line p-4">{{ $this->serials->links() }}</div>
        @endif
    </div>
</div>
