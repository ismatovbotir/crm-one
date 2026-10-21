{{-- Offer builder catalog search (own Livewire component: typing re-renders only this part). --}}
<div x-data="itemPicker" data-item-picker>
    <div class="grid gap-3 p-5 sm:grid-cols-[1fr_8rem]">
        <div>
            <label for="item-search" class="sr-only">{{ __('common.search') }}</label>
            <input wire:model.live.debounce.300ms="itemSearch" x-ref="search" @keydown.enter.prevent id="item-search" type="search" aria-controls="item-results" placeholder="{{ __('offers.builder.item_search') }}" class="form-input" autocomplete="off">
        </div>
        <div>
            <label for="item-quantity" class="sr-only">{{ __('offers.fields.quantity') }}</label>
            <input wire:model.live.debounce.300ms="itemQuantity" id="item-quantity" type="text" inputmode="decimal" class="form-input" placeholder="{{ __('offers.fields.quantity') }}">
            @error('itemQuantity') <p class="form-error">{{ $message }}</p> @enderror
        </div>
    </div>
    @if ($this->catalogResults !== [])
        <div class="overflow-x-auto border-t border-line" id="item-results" aria-live="polite">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-neutral-50 text-left text-xs font-semibold tracking-wide text-muted uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-2">{{ __('catalog.items.fields.sku') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('catalog.items.fields.name') }}</th>
                        <th scope="col" class="hidden px-4 py-2 lg:table-cell">{{ __('catalog.items.fields.accountant_code') }}</th>
                        <th scope="col" class="px-4 py-2">{{ __('offers.builder.stock') }}</th>
                        <th scope="col" class="px-4 py-2 text-right">{{ __('offers.builder.price_for_customer') }}</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($this->catalogResults as $result)
                        @php($item = $result['item'])
                        <tr wire:key="result-{{ $item->id }}" data-catalog-result="{{ $item->sku }}">
                            <td class="px-4 py-2 font-mono text-xs whitespace-nowrap text-ink">{{ $item->sku }}</td>
                            <td class="px-4 py-2">
                                <span class="text-ink">{{ $item->name }}</span>
                                <span class="mt-0.5 flex flex-wrap gap-1">
                                    @if ($result['analogue'])
                                        <x-badge tone="dark" icon="star">{{ __('offers.builder.linked_analogue', ['relation' => $result['analogue']]) }}</x-badge>
                                    @endif
                                    @if ($item->is_serialized)
                                        <x-badge tone="silver" icon="lock">{{ __('catalog.items.serialized') }}</x-badge>
                                    @endif
                                </span>
                                @if ($item->class_code)
                                    <span class="block font-mono text-xs text-muted">{{ __('catalog.items.fields.class_code') }}: {{ $item->class_code }}@if ($item->package_code) · {{ __('catalog.items.fields.package_code') }}: {{ $item->package_code }}@endif</span>
                                @endif
                            </td>
                            <td class="hidden px-4 py-2 font-mono text-xs text-ink lg:table-cell">{{ $item->accountant_code ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs whitespace-nowrap text-ink">
                                @if ($result['band'])
                                    <span class="inline-flex items-center gap-1"><x-icon :name="$result['band']->icon()" class="size-3.5" /> {{ $result['band']->label() }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap tabular-nums text-ink">{{ $result['price']?->format() ?? __('offers.builder.no_price') }}</td>
                            <td class="px-4 py-2 text-right">
                                <button type="button" wire:click="addItem({{ $item->id }})" class="btn btn-primary px-3 py-1.5" @disabled($result['price'] === null) wire:loading.attr="disabled">{{ __('common.add') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif (trim($itemSearch) !== '')
        <p class="border-t border-line px-5 py-3 text-sm text-muted">{{ __('offers.builder.no_items') }}</p>
    @endif
</div>
