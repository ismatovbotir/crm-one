<div class="space-y-6">
    @php
        $item = $this->item;
        $formatter = new \App\Documents\DocumentFormatter(\App\Enums\Locale::current());
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $tabs = $this->tabs();
        $sale = $item->salePrice();
        $purchase = $item->purchasePrice();
        $stockCost = $item->stockCostPrice();
    @endphp

    <a href="{{ route('items.index') }}" wire:navigate class="link text-sm">&larr; {{ __('catalog.items.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-xs text-muted">{{ $item->sku }}@if ($item->article) · {{ $item->article }}@endif</p>
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">{{ $item->name }}</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-badge tone="outline">{{ $item->type->label() }}</x-badge>
                    @if ($item->isDeletedInOneC())
                        <x-badge tone="danger-outline" icon="warning">{{ __('integration.deleted_in_1c') }}</x-badge>
                    @elseif ($item->is_active)
                        <x-badge tone="dark" icon="check">{{ __('common.active') }}</x-badge>
                    @else
                        <x-badge tone="outline">{{ __('common.inactive') }}</x-badge>
                    @endif
                    @if ($item->is_serialized)
                        <x-badge tone="silver" icon="lock" data-serialized-badge>{{ __('catalog.items.serialized') }}</x-badge>
                    @endif
                    @if (($sameSku = $item->sameSkuCount()) > 0)
                        <x-badge tone="outline" icon="warning" title="{{ __('catalog.items.duplicate_sku_hint') }}" data-duplicate-sku>{{ trans_choice('catalog.items.duplicate_sku', $sameSku, ['count' => $sameSku]) }}</x-badge>
                    @endif
                </div>
            </div>
            <div class="flex flex-col items-end gap-2">
                <x-onec-feed-status :last-received-at="$item->synced_at" class="sm:justify-end" />
                {{-- Spec 10: price-free portal catalog visibility. --}}
                <p class="text-xs text-muted" data-portal-visible="{{ $item->portal_visible ? 'yes' : 'no' }}">{{ $item->portal_visible ? __('requisitions.items.visible') : __('requisitions.items.hidden') }}
                    @can('managePortalCatalog')<button type="button" wire:click="togglePortal" class="link ml-1" data-action="toggle-portal">{{ $item->portal_visible ? __('requisitions.items.hide') : __('requisitions.items.show') }}</button>@endcan</p>
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.category') }}</dt><dd class="mt-1 text-ink">{{ $item->category ? collect([$item->category->parent?->name, $item->category->name])->filter()->join(' / ') : '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.unit') }}</dt><dd class="mt-1 text-ink">{{ $item->unit->name }} <span class="text-muted">({{ __('catalog.items.unit_precision', ['precision' => $item->unit->precision]) }})</span></dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.vat') }}</dt><dd class="mt-1 text-ink">{{ $item->is_vat_exempt ? __('catalog.items.vat_exempt') : $formatter->percent($item->vatPercent()) }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.available') }}</dt><dd class="mt-1 text-ink tabular-nums">{{ $item->type === \App\Enums\ItemType::Service ? '—' : $formatter->quantity($item->availableStock(), $item->unit->precision).' '.$item->unit->name }}</dd></div>
            @if ($item->description)
                <div class="sm:col-span-2 lg:col-span-4"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.description') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $item->description }}</dd></div>
            @endif
        </dl>
    </header>

    {{-- Item page tabs (templates/page-detail, x-tabs): heavy tabs load only when opened. --}}
    <x-tabs :tabs="$tabs" :active="$tab" property="tab" prefix="item" :label="__('catalog.items.tabs.label')">
        @if ($tab === 'overview')
            <div class="space-y-6">
    <section class="card" aria-labelledby="local-heading">
        <div class="border-b border-line px-6 py-4">
            <h3 id="local-heading" class="text-base font-semibold text-ink">{{ __('catalog.items.local_fields') }}</h3>
            <p class="mt-1 text-xs text-muted">{{ __('catalog.items.local_fields_hint') }}</p>
        </div>
        @can('updateLocalFields', $item)
            <form wire:submit="saveLocalFields" class="grid gap-4 p-6 sm:grid-cols-[1fr_1fr_auto] sm:items-start" novalidate>
                <div>
                    <label for="min_order_qty" class="form-label">{{ __('catalog.items.fields.min_order_qty') }}</label>
                    <input wire:model="min_order_qty" id="min_order_qty" type="text" inputmode="decimal" class="form-input" placeholder="{{ __('common.optional') }}">
                    @error('min_order_qty') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="order_step" class="form-label">{{ __('catalog.items.fields.order_step') }}</label>
                    <input wire:model="order_step" id="order_step" type="text" inputmode="decimal" class="form-input" placeholder="{{ __('common.optional') }}">
                    @error('order_step') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="btn btn-primary sm:mt-6" wire:loading.attr="disabled">{{ __('common.save') }}</button>
            </form>
        @else
            <dl class="grid gap-4 p-6 text-sm sm:grid-cols-2">
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.min_order_qty') }}</dt><dd class="mt-1 text-ink">{{ $item->min_order_qty !== null ? $formatter->quantity((string) $item->min_order_qty) : '—' }}</dd></div>
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.order_step') }}</dt><dd class="mt-1 text-ink">{{ $item->order_step !== null ? $formatter->quantity((string) $item->order_step) : '—' }}</dd></div>
            </dl>
        @endcan
    </section>

                <section class="card" aria-labelledby="onec-heading" data-onec-info>
                    <h3 id="onec-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('catalog.items.onec_title') }}</h3>
                    <dl class="grid gap-4 p-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('integration.onec_code') }}</dt><dd class="mt-1 font-mono text-ink">{{ $item->external_code ?? '—' }}</dd></div>
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.synced_at') }}</dt><dd class="mt-1 text-ink">{{ $item->synced_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) ?? '—' }}</dd></div>
                    </dl>
                </section>
            </div>
        @elseif ($tab === 'prices')
            <div class="card" data-tab-panel="prices">
                <dl class="grid gap-x-6 gap-y-4 p-6 text-sm sm:grid-cols-2 lg:grid-cols-4" data-item-prices>
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.sale_price') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink" data-sale-price>{{ $sale?->format() ?? __('catalog.items.no_price') }}</dd>
                        @if ($sale)
                            <dd class="text-xs text-muted">{{ $item->prices_include_vat ? __('catalog.items.incl_vat') : __('catalog.items.excl_vat') }}</dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.purchase_price') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink" data-purchase-price>{{ $purchase?->format() ?? __('catalog.items.no_price') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.stock_cost_price') }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink" data-stock-cost-price>{{ $stockCost?->format() ?? __('catalog.items.no_cost') }}</dd>
                        <dd class="text-xs text-muted">{{ __('catalog.items.stock_cost_hint') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.margin') }}</dt>
                        @php($cost = $item->costBasis((string) $sale?->currency))
                        @php($saleNet = $sale ? \App\Services\Pricing\ItemPricing::toBasis($sale->amount, $item->prices_include_vat, false, $item->is_vat_exempt ? 0 : $item->tax_rate_bp) : null)
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-ink" data-item-margin>
                            @if ($sale && $cost && $saleNet > 0)
                                {{ \App\Support\Money::of($saleNet - $cost->amount, $sale->currency)->format() }}
                                <span class="text-sm font-normal text-muted">({{ $formatter->percent(bcdiv(bcmul((string) ($saleNet - $cost->amount), '100', 2), (string) $saleNet, 2)) }})</span>
                            @else
                                —
                            @endif
                        </dd>
                        <dd class="text-xs text-muted">{{ __('catalog.items.margin_hint') }}</dd>
                    </div>
                </dl>
                <div class="border-t border-line">
                @if ($item->stocks->isEmpty())
                    <x-empty-state :title="__('catalog.items.no_stock')" icon="items" />
                @else
                    <table class="min-w-full divide-y divide-line text-sm">
                        <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                            <tr>
                                <th scope="col" class="px-6 py-3">{{ __('catalog.stock.warehouse') }}</th>
                                <th scope="col" class="px-6 py-3 text-right">{{ __('catalog.stock.quantity') }}</th>
                                <th scope="col" class="px-6 py-3 text-right">{{ __('catalog.stock.reserved') }}</th>
                                <th scope="col" class="px-6 py-3 text-right">{{ __('catalog.stock.available') }}</th>
                                <th scope="col" class="px-6 py-3 text-right">{{ __('catalog.stock.cost') }}</th>
                                <th scope="col" class="px-6 py-3">{{ __('catalog.stock.as_of') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($item->stocks as $stock)
                                <tr wire:key="stock-{{ $stock->id }}">
                                    <td class="px-6 py-2 text-ink">{{ $stock->warehouse->name }}</td>
                                    <td class="px-6 py-2 text-right tabular-nums">{{ $formatter->quantity((string) $stock->quantity) }}</td>
                                    <td class="px-6 py-2 text-right tabular-nums">{{ $formatter->quantity((string) $stock->reserved) }}</td>
                                    <td class="px-6 py-2 text-right font-medium tabular-nums text-ink">
                                        @if ($stock->isNegative())
                                            <span class="inline-flex items-center gap-1 text-brand-700" title="{{ __('catalog.stock.negative') }}"><x-icon name="warning" class="size-4" /><span class="sr-only">{{ __('catalog.stock.negative') }}</span></span>
                                        @endif
                                        {{ $formatter->quantity($stock->available()) }}
                                    </td>
                                    <td class="px-6 py-2 text-right whitespace-nowrap tabular-nums text-ink" data-stock-cost>{{ $stock->cost_minor !== null && $stock->cost_currency ? \App\Support\Money::of($stock->cost_minor, $stock->cost_currency)->format() : '—' }}</td>
                                    <td class="px-6 py-2 text-muted">{{ $stock->as_of->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                </div>
            </div>
        @elseif ($tab === 'codes')
            <div class="space-y-6">
    @php($canCodes = auth()->user()->can('updateAccountingCodes', $item))
    @php($canSerial = auth()->user()->can('updateSerialTracking', $item))
    @php($canWarranty = auth()->user()->can('updateWarranty', $item))
    <section class="card" aria-labelledby="codes-heading">
        <div class="border-b border-line px-6 py-4">
            <h3 id="codes-heading" class="text-base font-semibold text-ink">{{ __('catalog.items.codes_title') }}</h3>
            <p class="mt-1 text-xs text-muted">{{ __('catalog.items.codes_hint') }}</p>
        </div>
        @if ($canCodes || $canSerial || $canWarranty)
            <form wire:submit="saveAccountingFields" class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-4" novalidate>
                @foreach (['accountant_code' => 64, 'class_code' => 17, 'package_code' => 32] as $field => $max)
                    <div wire:key="code-{{ $field }}">
                        <label for="{{ $field }}" class="form-label">{{ __('catalog.items.fields.'.$field) }}</label>
                        <input wire:model.blur="{{ $field }}" id="{{ $field }}" type="text" maxlength="{{ $max }}" @if ($field !== 'accountant_code') inputmode="numeric" @endif @disabled(! $canCodes) class="form-input font-mono" placeholder="{{ __('common.optional') }}">
                        @error($field) <p class="form-error">{{ $message }}</p> @enderror
                        @if ($field === 'class_code' && $this->classCodeLengthWarning())
                            <p class="mt-1 flex items-center gap-1 text-xs text-muted" data-class-code-warning><x-icon name="warning" class="size-3.5" /> {{ __('catalog.items.class_code_length_warning') }}</p>
                        @endif
                    </div>
                @endforeach
                <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink">
                    <input wire:model="is_serialized" type="checkbox" @disabled(! $canSerial) class="size-4 accent-brand-600"> {{ __('catalog.items.serialized') }}
                </label>
                <div>
                    <label for="warranty_months" class="form-label">{{ __('catalog.items.fields.warranty_months') }}</label>
                    <input wire:model="warranty_months" id="warranty_months" type="number" min="0" max="240" @disabled(! $canWarranty) class="form-input" placeholder="{{ __('common.optional') }}">
                    @error('warranty_months') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2 lg:col-span-4 flex justify-end">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button>
                </div>
            </form>
        @else
            <dl class="grid gap-4 p-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
                @foreach (['accountant_code', 'class_code', 'package_code'] as $field)
                    <div wire:key="code-ro-{{ $field }}"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.fields.'.$field) }}</dt><dd class="mt-1 font-mono text-ink">{{ $item->{$field} ?? '—' }}</dd></div>
                @endforeach
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('catalog.items.serialized') }}</dt><dd class="mt-1 text-ink">{{ $item->is_serialized ? __('common.yes') : __('common.no') }}</dd></div>
            </dl>
        @endif
    </section>

    @php($canCargo = auth()->user()->can('updateCargo', $item))
    <section class="card" aria-labelledby="cargo-heading" data-cargo-fields>
        <div class="border-b border-line px-6 py-4">
            <h3 id="cargo-heading" class="text-base font-semibold text-ink">{{ __('catalog.items.cargo_title') }}</h3>
            <p class="mt-1 text-xs text-muted">{{ __('catalog.items.cargo_hint') }}</p>
        </div>
        <form wire:submit="saveCargoFields" class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-5" novalidate>
            @foreach (['hs_code', 'gross_weight_kg', 'net_weight_kg', 'volume_m3', 'country_of_origin'] as $field)
                <div wire:key="cargo-{{ $field }}">
                    <label for="cargo-{{ $field }}" class="form-label">{{ __('catalog.items.fields.'.$field) }}</label>
                    <input wire:model="cargo.{{ $field }}" id="cargo-{{ $field }}" type="text" @if ($field !== 'country_of_origin') inputmode="decimal" @endif @disabled(! $canCargo) class="form-input font-mono" placeholder="{{ __('common.optional') }}">
                    @error('cargo.'.$field) <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endforeach
            @if ($canCargo)
                <div class="flex justify-end sm:col-span-2 lg:col-span-5"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button></div>
            @endif
        </form>
    </section>

            </div>
        @elseif ($tab === 'serials')
            <section class="card" aria-label="{{ __('stock.serials.on_item') }}">
                <livewire:catalog.item-serials :item-id="$item->id" :key="'item-serials-'.$item->id" />
            </section>
        @elseif ($tab === 'analogues')
            <section class="card" aria-label="{{ __('catalog.items.tabs.analogues') }}">
                <livewire:catalog.item-analogues :item-id="$item->id" :key="'item-analogues-'.$item->id" />
            </section>
        @elseif ($tab === 'pictures')
            <livewire:catalog.item-gallery :item-id="$item->id" :key="'gallery-'.$item->id" />
        @elseif ($tab === 'usage')
            <section class="card" aria-label="{{ __('catalog.items.tabs.usage') }}">
                <livewire:catalog.item-usage :item-id="$item->id" :key="'item-usage-'.$item->id" />
            </section>
        @else
            <section class="card">
                <x-activity-log :entries="$activities" />
            </section>
        @endif
    </x-tabs>
</div>
