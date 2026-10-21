<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('orders.intro') }}</p>
        @can('create', \App\Models\Order::class)
            <a href="{{ route('orders.create') }}" wire:navigate class="btn btn-primary shrink-0" data-new-order><x-icon name="plus" class="size-4" /> {{ __('orders.new') }}</a>
        @endcan
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_auto] lg:items-end" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('orders.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="status-filter" class="sr-only">{{ __('common.status') }}</label>
                <select wire:model.live="status" id="status-filter" class="form-input">
                    <option value="">{{ __('common.all_statuses') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="payment-filter" class="sr-only">{{ __('orders.fields.payment_status') }}</label>
                <select wire:model.live="payment" id="payment-filter" class="form-input">
                    <option value="">{{ __('orders.any_payment_status') }}</option>
                    @foreach ($paymentStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="owner-filter" class="sr-only">{{ __('orders.fields.owner') }}</label>
                <select wire:model.live="owner" id="owner-filter" class="form-input">
                    <option value="">{{ __('orders.any_owner') }}</option>
                    <option value="mine">{{ __('orders.mine') }}</option>
                    @foreach ($this->owners as $ownerOption)
                        <option value="{{ $ownerOption->id }}" wire:key="owner-{{ $ownerOption->id }}">{{ $ownerOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="queue-filter" class="sr-only">{{ __('orders.queue') }}</label>
                <select wire:model.live="queue" id="queue-filter" class="form-input">
                    <option value="">{{ __('orders.queues.all') }}</option>
                    <option value="on_hold">{{ __('orders.queues.on_hold') }}</option>
                    <option value="agreement_missing">{{ __('orders.queues.agreement_missing') }}</option>
                    <option value="shipped_unpaid">{{ __('orders.queues.shipped_unpaid') }}</option>
                    <option value="poa_missing">{{ __('orders.queues.poa_missing') }}</option>
                </select>
            </div>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,status,payment,owner,queue,resetFilters,gotoPage,nextPage,previousPage">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('orders.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('orders.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('orders.fields.agreement') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('orders.fields.total') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('orders.fields.owner') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->orders as $order)
                        @php($agreement = $this->agreementOf($order))
                        <tr wire:key="order-{{ $order->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('orders.show', $order) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $order->number }}</a>
                                <span class="block text-xs text-muted">{{ $order->created_at->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }} · <span data-order-source="{{ $order->source->value }}">{{ $order->source->label() }}</span></span>
                            </td>
                            <td class="max-w-xs px-4 py-3"><span class="block truncate text-ink">{{ $order->customer?->name }}</span></td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <x-orders.status-badge :status="$order->status" />
                                    @php([$payTone, $payIcon] = $order->payment_status->badge())
                                    <x-badge :tone="$payTone" :icon="$payIcon">{{ $order->payment_status->label() }}</x-badge>
                                    @if ($order->on_hold)
                                        <x-badge tone="red" icon="lock" title="{{ collect($order->activeHolds())->map->label()->implode(', ') }}" data-on-hold>{{ __('orders.on_hold') }}</x-badge>
                                    @endif
                                </div>
                            </td>
                            <td class="hidden px-4 py-3 lg:table-cell">
                                @if ($agreement)
                                    <x-agreements.status-badge :status="$agreement->status" />
                                @elseif (! $order->agreement_required)
                                    <span class="text-xs text-muted">{{ __('orders.agreement_waived') }}</span>
                                @elseif ($order->framework_agreement_id)
                                    <span class="text-xs text-muted">{{ __('orders.under_framework') }}</span>
                                @else
                                    <span class="text-xs font-semibold text-brand-700">{{ __('orders.agreement_none') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap text-ink">{{ $order->money($order->gross_minor)->format() }}</td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-ink md:table-cell">{{ $order->owner?->name }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                <x-empty-state :title="__('orders.empty')" :description="__('orders.empty_hint')" icon="orders" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->orders->hasPages())
            <div class="border-t border-line p-4">{{ $this->orders->links() }}</div>
        @endif
    </div>
</div>
