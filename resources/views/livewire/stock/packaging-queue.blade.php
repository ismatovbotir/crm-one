<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('stock.packaging.intro') }}</p>

    <x-flash />

    <div class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-line p-4" role="search">
            <div class="flex flex-wrap gap-1" x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('stock.packaging.stage') }}">
                @foreach ($stageOptions as $option)
                    <button type="button" role="tab" aria-selected="{{ $stage === $option ? 'true' : 'false' }}" wire:click="$set('stage', '{{ $option }}')" wire:key="stage-{{ $option }}"
                            @class(['rounded-md px-3 py-1.5 text-sm font-semibold', 'bg-ink text-white' => $stage === $option, 'text-muted hover:text-ink' => $stage !== $option])>{{ __('stock.packaging.stages.'.$option) }}</button>
                @endforeach
            </div>
            <div class="min-w-56 flex-1">
                <label for="pq-search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="pq-search" type="search" placeholder="{{ __('stock.packaging.search') }}" class="form-input">
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="stage,search,gotoPage,nextPage,previousPage">
            <table class="min-w-full divide-y divide-line text-sm" data-packaging-queue>
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('orders.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('orders.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('orders.fields.sale_type') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('orders.payment_signal.column') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.packaging.next') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('orders.fields.promised_delivery_on') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.packaging.packing_list') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->orders as $order)
                        @php($list = $order->deliveries->firstWhere('status', \App\Enums\StockDocumentStatus::Draft))
                        <tr wire:key="pq-{{ $order->id }}" class="hover:bg-brand-50/40" data-order="{{ $order->number }}">
                            <td class="px-4 py-3 font-mono text-xs whitespace-nowrap"><a href="{{ route('orders.show', $order) }}" wire:navigate class="font-medium text-ink hover:text-brand-700 hover:underline">{{ $order->number }}</a></td>
                            <td class="px-4 py-3 text-ink">{{ $order->customer?->name }}</td>
                            <td class="px-4 py-3 text-ink">{{ $order->sale_type->label() }}</td>
                            <td class="px-4 py-3 whitespace-nowrap"><x-orders.status-badge :status="$order->status" /></td>
                            <td class="px-4 py-3"><x-orders.payment-signal :order="$order" /></td>
                            <td class="px-4 py-3 text-ink">
                                @if (in_array($order->status, [\App\Enums\OrderStatus::Confirmed, \App\Enums\OrderStatus::PartiallyDelivered], true) && ! ($gate->agreementSatisfied($order) && $gate->paymentSatisfied($order)))
                                    <span class="inline-flex items-center gap-1 text-brand-800" data-gate="blocked"><x-icon name="warning" class="size-4" /> {{ ! $gate->agreementSatisfied($order) ? __('stock.packaging.waiting_agreement') : __('stock.packaging.waiting_payment') }}</span>
                                @elseif ($next = $flow->next($order))
                                    {{ __('orders.flow.next.'.$next) }}
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-ink md:table-cell">{{ $order->promised_delivery_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($list)
                                    <a href="{{ route('delivery-notes.show', $list) }}" wire:navigate class="link font-mono">{{ $list->number }}</a>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="p-0"><x-empty-state :title="__('stock.packaging.empty')" icon="orders" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->orders->hasPages())
            <div class="border-t border-line p-4">{{ $this->orders->links() }}</div>
        @endif
    </div>
</div>
