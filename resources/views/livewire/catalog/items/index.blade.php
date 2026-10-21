<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('catalog.items.intro') }}</p>
        <x-onec-feed-status :last-received-at="$this->lastReceivedAt" />
    </div>

    <x-onec-stale-alert :statuses="$this->feedStatuses" />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto] lg:items-center" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('catalog.items.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="category-filter" class="sr-only">{{ __('catalog.items.fields.category') }}</label>
                <select wire:model.live="category" id="category-filter" class="form-input">
                    <option value="">{{ __('catalog.items.all_categories') }}</option>
                    @foreach ($categories as $categoryOption)
                        <option value="{{ $categoryOption->id }}" wire:key="cat-{{ $categoryOption->id }}">{{ $categoryOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type-filter" class="sr-only">{{ __('catalog.items.fields.type') }}</label>
                <select wire:model.live="type" id="type-filter" class="form-input">
                    <option value="">{{ __('catalog.items.all_types') }}</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="status-filter" class="sr-only">{{ __('common.status') }}</label>
                <select wire:model.live="status" id="status-filter" class="form-input">
                    <option value="active">{{ __('common.active') }}</option>
                    <option value="inactive">{{ __('common.inactive') }}</option>
                    <option value="all">{{ __('common.all') }}</option>
                </select>
            </div>
            <div>
                <label for="serialized-filter" class="sr-only">{{ __('catalog.items.serialized') }}</label>
                <select wire:model.live="serialized" id="serialized-filter" class="form-input">
                    <option value="">{{ __('catalog.items.serialized_any') }}</option>
                    <option value="yes">{{ __('catalog.items.serialized') }}</option>
                    <option value="no">{{ __('catalog.items.not_serialized') }}</option>
                </select>
            </div>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
            <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2 lg:col-span-6">
                <input wire:model.live="showCodes" type="checkbox" class="size-4 accent-brand-600"> {{ __('catalog.items.show_codes') }}
            </label>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,category,type,status,serialized,showCodes,resetFilters,gotoPage,nextPage,previousPage">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="w-14 px-4 py-3"><span class="sr-only">{{ __('catalog.images.column') }}</span></th>
                        <th scope="col" class="px-4 py-3">{{ __('catalog.items.fields.sku') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('catalog.items.fields.name') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('catalog.items.fields.accountant_code') }}</th>
                        @if ($showCodes)
                            <th scope="col" class="px-4 py-3">{{ __('catalog.items.fields.class_code') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('catalog.items.fields.package_code') }}</th>
                        @endif
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('catalog.items.fields.category') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('catalog.items.fields.unit') }}</th>
                        <th scope="col" class="hidden px-4 py-3 text-right md:table-cell">{{ __('catalog.items.fields.vat') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('catalog.items.sale_price') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('catalog.items.available') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->items as $item)
                        @php($formatter = new \App\Documents\DocumentFormatter(\App\Enums\Locale::current()))
                        <tr wire:key="item-{{ $item->id }}" @class(['bg-neutral-50' => ! $item->isSellable()])>
                            <td class="px-4 py-2"><x-item-thumb :url="$item->mainImage?->url('thumb')" :alt="$item->name" :size="40" /></td>
                            <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-ink">
                                {{ $item->sku }}
                                @if (in_array($item->sku, $this->duplicateSkus, true))
                                    <x-badge tone="outline" icon="warning" class="ml-1 font-sans" title="{{ __('catalog.items.duplicate_sku_hint') }}" data-duplicate-sku>{{ __('catalog.items.duplicate_sku_short') }}</x-badge>
                                @endif
                                @if ($item->article)
                                    <span class="block text-muted">{{ $item->article }}</span>
                                @endif
                            </td>
                            <td class="max-w-sm px-4 py-3">
                                <a href="{{ route('items.show', $item) }}" wire:navigate class="block truncate font-medium text-ink hover:text-brand-700 hover:underline">{{ $item->name }}</a>
                                <span class="mt-0.5 flex flex-wrap gap-1">
                                    <x-badge tone="outline">{{ $item->type->label() }}</x-badge>
                                    @if ($item->is_serialized)
                                        <x-badge tone="silver" icon="lock">{{ __('catalog.items.serialized') }}</x-badge>
                                    @endif
                                    @if ($item->isDeletedInOneC())
                                        <x-badge tone="danger-outline" icon="warning">{{ __('integration.deleted_in_1c') }}</x-badge>
                                    @elseif (! $item->is_active)
                                        <x-badge tone="outline">{{ __('common.inactive') }}</x-badge>
                                    @endif
                                </span>
                            </td>
                            <td class="hidden px-4 py-3 font-mono text-xs text-ink md:table-cell">{{ $item->accountant_code ?? '—' }}</td>
                            @if ($showCodes)
                                <td class="px-4 py-3 font-mono text-xs text-ink">{{ $item->class_code ?? '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs text-ink">{{ $item->package_code ?? '—' }}</td>
                            @endif
                            <td class="hidden px-4 py-3 text-ink lg:table-cell">{{ $item->category?->name ?? '—' }}</td>
                            <td class="hidden px-4 py-3 text-ink md:table-cell">{{ $item->unit->name }}</td>
                            <td class="hidden px-4 py-3 text-right tabular-nums md:table-cell">{{ $item->is_vat_exempt ? __('catalog.items.vat_exempt') : $formatter->percent($item->vatPercent()) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-ink">{{ $item->salePrice()?->format() ?? '—' }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-ink">{{ $item->type === \App\Enums\ItemType::Service ? '—' : $formatter->quantity($item->availableStock(), $item->unit->precision) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $showCodes ? 11 : 9 }}" class="p-0">
                                <x-empty-state :title="__('catalog.items.empty')" :description="__('integration.empty.catalog')" icon="items" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->items->hasPages())
            <div class="border-t border-line p-4">{{ $this->items->links() }}</div>
        @endif
    </div>
</div>
