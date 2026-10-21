<div class="space-y-6">
    @php
        $delivery = $this->delivery;
        $canEdit = auth()->user()->can('update', $delivery);
    @endphp

    <a href="{{ route('delivery-notes.index') }}" wire:navigate class="link text-sm">&larr; {{ __('stock.deliveries.back') }}</a>

    <x-flash />

    <header class="card flex flex-col gap-4 p-6 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="font-mono text-sm text-muted">{{ $delivery->warehouse?->name }} @if ($delivery->delivered_on) · {{ $delivery->delivered_on->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}@endif</p>
            <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ $delivery->number }}</h2>
            <p class="mt-1 text-sm text-ink">@can('view', $delivery->order)<a href="{{ route('orders.show', $delivery->order) }}" wire:navigate class="link font-mono">{{ $delivery->order->number }}</a>@else<span class="font-mono">{{ $delivery->order?->number }}</span>@endcan · {{ $delivery->order?->customer?->name }}</p>
            <div class="mt-3 flex flex-wrap gap-2">
                @php([$tone, $icon] = $delivery->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $delivery->status->label() }}</x-badge>
                @if ($delivery->status === \App\Enums\StockDocumentStatus::Draft)
                    <x-badge :tone="$delivery->isPacked() ? 'dark' : 'outline'" :icon="$delivery->isPacked() ? 'check' : 'clock'" data-packing="{{ $delivery->isPacked() ? 'packed' : 'packing' }}">{{ $delivery->isPacked() ? __('stock.packaging.packed') : __('stock.packaging.packing') }}</x-badge>
                @endif
            </div>
            @if ($delivery->order)
                <div class="mt-2"><x-orders.payment-signal :order="$delivery->order" /></div>
                @if ($delivery->status === \App\Enums\StockDocumentStatus::Draft && in_array($delivery->order->paymentSignal()['state'], ['unpaid', 'partial', 'released'], true))
                    <p class="mt-2 flex items-start gap-1 text-sm text-brand-800" data-unpaid-warning><x-icon name="warning" class="mt-0.5 size-4 shrink-0" /> {{ __('orders.payment_signal.ship_unpaid_warning') }}</p>
                @endif
            @endif
            @if ($delivery->isPacked())
                <p class="mt-2 text-xs text-muted">{{ __('stock.packaging.packed_by', ['name' => $delivery->packer?->name ?? '—', 'at' => $delivery->packed_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}</p>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            @can('print', $delivery)
                <a href="{{ route('delivery-notes.print', $delivery) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('stock.actions.print') }}</a>
            @endcan
            @can('completePackaging', $delivery)
                <button type="button" wire:click="completePackaging" class="btn btn-primary" wire:loading.attr="disabled" data-action="complete-packaging" @disabled($this->incomplete !== [])>{{ __('stock.packaging.complete') }}</button>
            @endcan
            @can('reopenPackaging', $delivery)
                <button type="button" wire:click="$set('reopening', true)" class="btn btn-secondary" data-action="reopen-packaging">{{ __('stock.packaging.reopen') }}</button>
            @endcan
            @can('post', $delivery)
                <button type="button" wire:click="post" class="btn btn-primary" wire:loading.attr="disabled" data-action="post">{{ __('stock.actions.post') }}</button>
            @endcan
            @can('update', $delivery)
                <button type="button" wire:click="cancel" wire:confirm="{{ __('stock.deliveries.cancel_confirm') }}" class="btn btn-danger-outline" data-action="cancel">{{ __('stock.actions.cancel_draft') }}</button>
            @endcan
        </div>
    </header>

    @if ($delivery->order?->isPickup())
        <section class="card p-5" data-pickup-handover>
            <h3 class="font-semibold text-ink">{{ __('stock.pickup.title') }}</h3>
            @if ($delivery->status === \App\Enums\StockDocumentStatus::Draft && auth()->user()->can('post', $delivery))
                <form wire:submit="savePickup" class="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end" novalidate>
                    <div>
                        <label for="pickup-person" class="form-label">{{ __('stock.pickup.person') }} <x-required-mark /></label>
                        <input wire:model="pickupPerson" id="pickup-person" type="text" maxlength="160" class="form-input">
                        @error('pickupPerson') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="pickup-document" class="form-label">{{ __('stock.pickup.document') }} <x-required-mark /></label>
                        <input wire:model="pickupDocument" id="pickup-document" type="text" maxlength="60" class="form-input">
                        @error('pickupDocument') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button>
                </form>
            @else
                <p class="mt-2 text-sm text-ink">{{ $delivery->pickup_person }} · {{ $delivery->pickup_document }}</p>
            @endif
        </section>
    @endif

    @if ($reopening)
        <form wire:submit="reopenPackaging" class="card flex flex-wrap items-end gap-3 p-5" data-panel="reopen-packaging" novalidate>
            <div class="min-w-64 flex-1">
                <label for="reopen-reason" class="form-label">{{ __('stock.packaging.reopen_reason') }}</label>
                <input wire:model="reopenReason" id="reopen-reason" type="text" maxlength="500" class="form-input">
                @error('reopenReason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <button type="button" wire:click="$set('reopening', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
            <button type="submit" class="btn btn-primary">{{ __('stock.packaging.reopen') }}</button>
        </form>
    @endif

    @if ($this->incomplete !== [] && $delivery->status === \App\Enums\StockDocumentStatus::Draft)
        <p class="rounded-md border border-line-strong bg-neutral-50 px-3 py-2 text-sm text-ink" data-incomplete>{{ __('stock.deliveries.incomplete', ['lines' => implode('; ', $this->incomplete)]) }}</p>
    @endif

    {{-- Standard document tabs (user decision 2026-10-20): Header | Items | Other. --}}
    <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
        @if ($tab === 'header')
            <section class="card p-5" aria-label="{{ __('common.document_tabs.header') }}" data-delivery-header>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.deliveries.order') }}</dt><dd class="mt-1 text-ink">@can('view', $delivery->order)<a href="{{ route('orders.show', $delivery->order) }}" wire:navigate class="link font-mono">{{ $delivery->order->number }}</a>@else<span class="font-mono">{{ $delivery->order?->number }}</span>@endcan</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.customer') }}</dt><dd class="mt-1 text-ink">{{ $delivery->order?->customer?->name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.warehouse') }}</dt><dd class="mt-1 text-ink">{{ $delivery->warehouse?->name }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.delivery_method') }}</dt><dd class="mt-1 text-ink">{{ $delivery->order?->delivery_method?->label() ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.deliveries.delivered_on') }}</dt><dd class="mt-1 text-ink">{{ $delivery->delivered_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</dd></div>
                    @if ($delivery->isPacked())
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.packaging.packed') }}</dt><dd class="mt-1 text-ink">{{ __('stock.packaging.packed_by', ['name' => $delivery->packer?->name ?? '—', 'at' => $delivery->packed_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}</dd></div>
                    @endif
                    @if ($delivery->order?->delivery_address)
                        <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.delivery_address') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $delivery->order->delivery_address }}</dd></div>
                    @endif
                </dl>
            </section>
        @elseif ($tab === 'items')
            <div class="space-y-6">
                @foreach ($delivery->lines as $line)
                    <section class="card space-y-3 p-5" wire:key="dl-{{ $line->id }}" data-delivery-line="{{ $line->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold text-ink">{{ $line->orderLine?->option_label }}. {{ $line->item?->name }} <span class="font-mono text-xs text-muted">{{ $line->item?->sku }}</span> @if ($line->is_serialized) <x-badge tone="outline">{{ __('orders.lines.serialized') }}</x-badge> @endif</p>
                            <p class="text-sm text-muted">{{ __('stock.deliveries.ordered', ['ordered' => \App\Support\Quantity::trimmed((string) $line->orderLine?->quantity), 'delivered' => \App\Support\Quantity::trimmed((string) $line->orderLine?->delivered_qty), 'unit' => $line->orderLine?->unit_name]) }}</p>
                        </div>
                        @if ($line->is_serialized)
                            <livewire:stock.serial-entry :line-type="$serialLineType" :line-id="$line->id" :key="'se-dn-'.$line->id" />
                        @else
                            <div class="flex items-end gap-2">
                                <div>
                                    <label for="q-{{ $line->id }}" class="text-xs text-muted">{{ __('stock.fields.quantity') }}</label>
                                    <input wire:model="quantities.{{ $line->id }}" id="q-{{ $line->id }}" type="text" inputmode="decimal" class="form-input w-32" @disabled(! $canEdit)>
                                </div>
                                @if ($canEdit)
                                    <button type="button" wire:click="saveQuantity({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        @else
            <div class="space-y-4" data-delivery-other>
                <section class="card p-5" aria-labelledby="dn-related-heading">
                    <h3 id="dn-related-heading" class="font-semibold text-ink">{{ __('common.document_tabs.related') }}</h3>
                    <ul class="mt-3 divide-y divide-line text-sm">
                        <li class="flex flex-wrap items-center justify-between gap-3 py-2">
                            <span class="text-muted">{{ __('stock.deliveries.order') }}</span>
                            @can('view', $delivery->order)<a href="{{ route('orders.show', $delivery->order) }}" wire:navigate class="link font-mono">{{ $delivery->order->number }}</a>@else<span class="font-mono">{{ $delivery->order?->number }}</span>@endcan
                        </li>
                    </ul>
                </section>
                <section class="card" aria-labelledby="dn-history-heading" data-delivery-history>
                    <h3 id="dn-history-heading" class="px-5 pt-5 font-semibold text-ink">{{ __('common.document_tabs.history') }}</h3>
                    <x-activity-log :entries="$this->activities" />
                </section>
            </div>
        @endif
    </x-tabs>
</div>
