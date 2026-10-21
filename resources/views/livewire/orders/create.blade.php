<div class="space-y-6">
    <a href="{{ route('orders.index') }}" wire:navigate class="link text-sm">&larr; {{ __('orders.create.back') }}</a>

    <x-flash />

    <p class="text-sm text-muted">{{ __('orders.create.intro') }}</p>

    <form wire:submit="save" class="space-y-6" novalidate data-manual-order-form>
        {{-- Summary (always visible above the tabs): customer and line count. --}}
        <header class="card flex flex-wrap items-center justify-between gap-3 p-5" data-document-summary>
            <div class="min-w-0">
                <p class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.customer') }}</p>
                <p class="font-semibold break-words text-ink">{{ $this->customer?->name ?? '—' }}</p>
            </div>
            <p class="text-sm text-muted">{{ __('orders.create.lines') }}: <span class="font-semibold text-ink tabular-nums">{{ count($lines) }}</span></p>
        </header>

        {{-- Standard document tabs (user decision 2026-10-20): Header | Items, one form. --}}
        <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
            @if ($tab === 'header')
                <div class="card space-y-5 p-6">
                <div>
                    <label for="mo-customer" class="form-label">{{ __('orders.fields.customer') }}</label>
                    <input x-data @keydown.enter.prevent wire:model.live.debounce.300ms="customerSearch" id="mo-customer" type="search" class="form-input" placeholder="{{ __('orders.create.customer_search') }}" autocomplete="off" @disabled($customerId !== '')>
                    @if ($customerSearch !== '' && $customerId === '')
                        <ul class="mt-1 max-h-56 overflow-y-auto rounded-md border border-line bg-surface text-sm shadow-sm">
                            @foreach ($this->customerOptions as $option)
                                <li wire:key="mc-{{ $option->id }}"><button type="button" wire:click="selectCustomer({{ $option->id }})" class="w-full px-3 py-2 text-left hover:bg-brand-50">{{ $option->name }} <span class="text-muted">{{ $option->tax_id }}</span></button></li>
                            @endforeach
                        </ul>
                    @elseif ($customerId !== '')
                        <button type="button" wire:click="clearCustomer" class="mt-1 text-xs link">{{ __('orders.create.change_customer') }}</button>
                    @endif
                    @error('customerId') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="mo-sale-type" class="form-label">{{ __('orders.fields.sale_type') }}</label>
                        <select wire:model.live="saleType" id="mo-sale-type" class="form-input">
                            <option value="">—</option>
                            @foreach ($saleTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('saleType') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <fieldset>
                        <legend class="form-label">{{ __('orders.fields.delivery_method') }}</legend>
                        <div class="flex gap-4 pt-2">
                            @foreach ($deliveryMethods as $value => $label)
                                <label class="flex items-center gap-2 text-sm text-ink"><input type="radio" wire:model.live="deliveryMethod" value="{{ $value }}" class="accent-brand-600"> {{ $label }}</label>
                            @endforeach
                        </div>
                    </fieldset>
                    <div>
                        <label for="mo-warehouse" class="form-label">{{ __('orders.fields.warehouse') }}</label>
                        <select wire:model="warehouseId" id="mo-warehouse" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->warehouses as $warehouse)
                                <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                        @error('warehouseId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($deliveryMethod === 'delivery')
                        <div class="sm:col-span-3">
                            <label for="mo-address" class="form-label">{{ __('orders.fields.delivery_address') }} <x-required-mark /></label>
                            <input wire:model="deliveryAddress" id="mo-address" type="text" class="form-input" maxlength="2000">
                            @error('deliveryAddress') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <div class="sm:col-span-2">
                        <label for="mo-note" class="form-label">{{ __('orders.fields.note') }}</label>
                        <input wire:model="note" id="mo-note" type="text" class="form-input" maxlength="2000">
                    </div>
                    @if ($saleType === 'b2b')
                        <label class="flex items-end gap-2 pb-2 text-sm text-ink"><input type="checkbox" wire:model="ownAgreement" class="size-4 accent-brand-600"> {{ __('orders.create.own_agreement') }}</label>
                    @endif
                </div>
                </div>
            @else
                <div class="card p-6">
                <section class="space-y-3" data-manual-order-lines>
                    <h3 class="font-semibold text-ink">{{ __('orders.create.lines') }}</h3>
                    <div>
                        <label for="mo-item" class="sr-only">{{ __('orders.create.item_search') }}</label>
                        <input x-data @keydown.enter.prevent wire:model.live.debounce.300ms="itemSearch" id="mo-item" type="search" class="form-input" placeholder="{{ __('orders.create.item_search') }}">
                        <ul class="mt-1 divide-y divide-line text-sm">
                            @foreach ($this->itemOptions as $item)
                                <li class="flex items-center justify-between py-2" wire:key="mi-{{ $item->id }}">
                                    <span><span class="font-mono text-muted">{{ $item->sku }}</span> {{ $item->name }}</span>
                                    <button type="button" wire:click="addItem({{ $item->id }})" class="btn btn-secondary btn-sm">{{ __('orders.create.add') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    @error('lines') <p class="form-error">{{ $message }}</p> @enderror
                    @if ($lines !== [])
                        <table class="min-w-full divide-y divide-line text-sm">
                            <thead class="text-left text-xs font-semibold text-muted uppercase">
                                <tr><th scope="col" class="py-2 pr-3">{{ __('orders.lines.name') }}</th><th scope="col" class="py-2 pr-3">{{ __('orders.lines.quantity') }}</th><th scope="col" class="py-2 pr-3">{{ __('orders.create.discount', ['limit' => rtrim(rtrim(bcdiv((string) $discountLimit, '100', 2), '0'), '.')]) }}</th><th scope="col"><span class="sr-only">{{ __('orders.create.remove') }}</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($lines as $index => $line)
                                    <tr wire:key="ml-{{ $line['item_id'] }}">
                                        <td class="py-2 pr-3"><span class="font-mono text-xs text-muted">{{ $line['sku'] }}</span> {{ $line['name'] }}</td>
                                        <td class="py-2 pr-3"><label for="mq-{{ $index }}" class="sr-only">{{ __('orders.lines.quantity') }}</label><input wire:model="lines.{{ $index }}.quantity" id="mq-{{ $index }}" type="text" inputmode="decimal" class="form-input w-28">@error('lines.'.$index.'.quantity') <p class="form-error">{{ $message }}</p> @enderror</td>
                                        <td class="py-2 pr-3"><label for="md-{{ $index }}" class="sr-only">{{ __('orders.create.discount', ['limit' => '']) }}</label><input wire:model="lines.{{ $index }}.discount" id="md-{{ $index }}" type="text" inputmode="decimal" class="form-input w-24">@error('lines.'.$index.'.discount') <p class="form-error">{{ $message }}</p> @enderror</td>
                                        <td class="py-2 text-right"><button type="button" wire:click="removeLine({{ $index }})" class="btn btn-secondary btn-sm">{{ __('orders.create.remove') }}</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                    <p class="text-xs text-muted">{{ __('orders.create.pricing_note') }}</p>
                </section>
                </div>
            @endif
        </x-tabs>

        <div class="flex justify-end gap-3">
            <a href="{{ route('orders.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" data-save-manual-order>{{ __('orders.create.save') }}</button>
        </div>
    </form>
</div>
