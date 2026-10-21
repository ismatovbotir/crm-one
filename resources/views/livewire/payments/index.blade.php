<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('accounting.payments.intro') }}</p>
        @can('create', \App\Models\Payment::class)
            <a href="{{ route('payments.for-orders') }}" wire:navigate class="btn btn-primary shrink-0"><x-icon name="plus" class="size-4" /> {{ __('accounting.payments.new') }}</a>
        @endcan
    </div>

    <x-flash />

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5" data-queues>
        @foreach (\App\Livewire\Payments\Index::QUEUES as $queueKey)
            <button type="button" wire:click="$set('queue', '{{ $queue === $queueKey ? '' : $queueKey }}')" wire:key="pq-{{ $queueKey }}"
                    @class(['card p-4 text-left transition-colors', 'border-2 border-brand-600' => $queue === $queueKey, 'hover:bg-brand-50/40' => $queue !== $queueKey])>
                <span class="block text-2xl font-semibold text-ink">{{ $this->queueCounts[$queueKey] }}</span>
                <span class="text-sm text-muted">{{ __('accounting.payments.queues.'.$queueKey) }}</span>
            </button>
        @endforeach
    </div>

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('accounting.payments.search_placeholder') }}" class="form-input">
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
                <label for="source-filter" class="sr-only">{{ __('accounting.fields.source') }}</label>
                <select wire:model.live="source" id="source-filter" class="form-input">
                    <option value="">{{ __('accounting.payments.any_source') }}</option>
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('accounting.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('accounting.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('accounting.fields.amount') }}</th>
                        <th scope="col" class="hidden px-4 py-3 text-right md:table-cell">{{ __('accounting.fields.unallocated') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->payments as $payment)
                        <tr wire:key="pay-{{ $payment->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('payments.show', $payment) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $payment->number }}</a>
                                <span class="block text-xs text-muted">{{ $payment->paid_on->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }} · {{ $payment->method->label() }} · {{ $payment->source->label() }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-3"><span class="block truncate text-ink">{{ $payment->customer?->name ?? __('accounting.payment.unmatched') }}</span><span class="block truncate text-xs text-muted">{{ $payment->payer_name }}</span></td>
                            <td class="px-4 py-3">
                                <x-accounting.payment-status :payment="$payment" />
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap text-ink">@if ($payment->kind === \App\Enums\PaymentKind::Refund)−@endif{{ $payment->money()->format() }}</td>
                            <td class="hidden px-4 py-3 text-right whitespace-nowrap md:table-cell">{{ $payment->kind === \App\Enums\PaymentKind::Receipt ? $payment->money($payment->unallocatedMinor())->format() : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-0"><x-empty-state :title="__('accounting.payments.empty')" icon="orders" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->payments->hasPages())
            <div class="border-t border-line p-4">{{ $this->payments->links() }}</div>
        @endif
    </div>
</div>
