<div class="space-y-6">
    <a href="{{ route('payments.index') }}" wire:navigate class="link text-sm">&larr; {{ __('accounting.payments.back') }}</a>

    <x-flash />

    <p class="text-sm text-muted">{{ __('accounting.order_payments.intro') }}</p>

    <form wire:submit="save" class="card space-y-5 p-6" novalidate data-order-payment-form>
        <div>
            <label for="op-customer" class="form-label">{{ __('accounting.fields.customer') }}</label>
            <input x-data @keydown.enter.prevent wire:model.live.debounce.300ms="customerSearch" id="op-customer" type="search" class="form-input" placeholder="{{ __('accounting.payments.customer_search') }}" autocomplete="off" @disabled($customerId !== '')>
            @if ($customerSearch !== '' && $customerId === '')
                <ul class="mt-1 max-h-56 overflow-y-auto rounded-md border border-line bg-surface text-sm shadow-sm">
                    @foreach ($this->customerOptions as $option)
                        <li wire:key="oc-{{ $option->id }}"><button type="button" wire:click="selectCustomer({{ $option->id }})" class="w-full px-3 py-2 text-left hover:bg-brand-50">{{ $option->name }} <span class="text-muted">{{ $option->tax_id }}</span></button></li>
                    @endforeach
                </ul>
            @elseif ($customerId !== '')
                <button type="button" wire:click="clearCustomer" class="mt-1 text-xs link">{{ __('accounting.payments.change_customer') }}</button>
            @endif
            @error('customerId') <p class="form-error">{{ $message }}</p> @enderror
        </div>

        @if ($customerId !== '')
            <div class="overflow-x-auto" data-unpaid-orders>
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="text-left text-xs font-semibold text-muted uppercase">
                        <tr>
                            <th scope="col" class="py-2 pr-3"><span class="sr-only">{{ __('accounting.order_payments.select') }}</span></th>
                            <th scope="col" class="py-2 pr-3">{{ __('accounting.order_payments.order') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right">{{ __('accounting.order_payments.total') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right">{{ __('accounting.order_payments.paid') }}</th>
                            <th scope="col" class="py-2 pr-3 text-right">{{ __('accounting.order_payments.outstanding') }}</th>
                            <th scope="col" class="py-2">{{ __('accounting.order_payments.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($this->orders as $order)
                            @php($outstanding = $this->outstanding($order))
                            <tr wire:key="uo-{{ $order->id }}" data-order-row="{{ $order->id }}">
                                <td class="py-2 pr-3"><input type="checkbox" wire:model.live="selected.{{ $order->id }}" id="sel-{{ $order->id }}" class="size-4 accent-brand-600"></td>
                                <td class="py-2 pr-3"><label for="sel-{{ $order->id }}" class="font-mono text-ink">{{ $order->number }}</label><span class="block text-xs text-muted">{{ $order->confirmed_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</span></td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $order->money($order->gross_minor)->format() }}</td>
                                <td class="py-2 pr-3 text-right whitespace-nowrap">{{ $order->money($order->gross_minor - $outstanding)->format() }}</td>
                                <td class="py-2 pr-3 text-right font-semibold whitespace-nowrap">{{ $order->money($outstanding)->format() }}</td>
                                <td class="py-2">
                                    <label for="amt-{{ $order->id }}" class="sr-only">{{ __('accounting.order_payments.amount') }} {{ $order->number }}</label>
                                    <div class="flex items-center gap-1">
                                        <input wire:model.live.debounce.400ms="amounts.{{ $order->id }}" id="amt-{{ $order->id }}" type="text" inputmode="decimal" class="form-input w-36" @disabled(! ($selected[$order->id] ?? false))>
                                        <span class="text-xs text-muted">{{ $order->currency }}</span>
                                    </div>
                                    @error('amounts.'.$order->id) <p class="form-error">{{ $message }}</p> @enderror
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-muted">{{ __('accounting.order_payments.no_orders') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($this->totals !== [])
                <p class="text-sm font-semibold text-ink" data-order-payment-total>{{ __('accounting.order_payments.total_selected') }}
                    @foreach ($this->totals as $currency => $minor) {{ \App\Support\Money::of($minor, $currency)->format() }}@if (! $loop->last), @endif @endforeach
                </p>
            @endif
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="op-date" class="form-label">{{ __('accounting.fields.paid_on') }}</label>
                <input wire:model="paidOn" id="op-date" type="date" class="form-input">
                @error('paidOn') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="op-method" class="form-label">{{ __('accounting.fields.method') }}</label>
                <select wire:model.live="method" id="op-method" class="form-input">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($method !== 'cash')
                <div>
                    <label for="op-ref" class="form-label">{{ __('accounting.fields.reference_number') }}</label>
                    <input wire:model="referenceNumber" id="op-ref" type="text" class="form-input">
                    @error('referenceNumber') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div class="sm:col-span-2">
                <label for="op-note" class="form-label">{{ __('accounting.fields.note') }}</label>
                <input wire:model="note" id="op-note" type="text" class="form-input" maxlength="1000">
            </div>
            <div>
                <label for="op-proof" class="form-label">{{ __('accounting.fields.proof') }}</label>
                <input wire:model="proof" id="op-proof" type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-input">
                @error('proof') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </div>

        @if ($confirmDuplicate)
            <p class="rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-duplicate-warning>{{ __('accounting.order_payments.duplicate') }}</p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a href="{{ route('payments.create') }}" wire:navigate class="link text-sm">{{ __('accounting.order_payments.detailed') }}</a>
            <div class="flex gap-3">
                <a href="{{ route('payments.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" data-save-order-payments>{{ __('accounting.order_payments.save') }}</button>
            </div>
        </div>
    </form>
</div>
