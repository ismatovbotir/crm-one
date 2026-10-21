<div class="space-y-6">
    @php
        $serial = $this->serial;
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $showCustomer = auth()->user()->can('viewCustomer', $serial);
    @endphp

    <a href="{{ route('serials.index') }}" wire:navigate class="link text-sm">&larr; {{ __('stock.serials.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <p class="text-sm text-muted">{{ $serial->item?->name }} · <span class="font-mono">{{ $serial->item?->sku }}</span></p>
        <h2 class="font-mono text-2xl font-semibold tracking-tight text-ink">{{ $serial->serial_normalized }}</h2>
        <div class="mt-3">@php([$tone, $icon] = $serial->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $serial->status->label() }}</x-badge></div>
        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.warehouse') }}</dt><dd class="mt-1 text-ink">{{ $serial->warehouse?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.received') }}</dt><dd class="mt-1 text-ink">{{ $serial->receiptLine?->receipt?->number ?? $serial->source->label() }} · {{ $serial->received_at?->translatedFormat($dateFormat) }}</dd></div>
            @if ($serial->sold_at)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.sold') }}</dt><dd class="mt-1 text-ink">{{ $serial->soldDeliveryLine?->delivery?->number }} · {{ $serial->sold_at->translatedFormat($dateFormat) }}@if ($showCustomer) · {{ $serial->customer?->name }}@endif</dd></div>
                @php($customerAsset = \App\Models\CustomerAsset::query()->where('item_serial_id', $serial->id)->first())
                @if ($customerAsset && auth()->user()->can('view', $customerAsset))
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('service.customer_asset') }}</dt><dd class="mt-1"><a href="{{ route('service.assets.show', $customerAsset) }}" wire:navigate class="link" data-customer-asset-link>{{ $customerAsset->status->label() }}</a></dd></div>
                @endif
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.warranty_until') }}</dt><dd class="mt-1 text-ink">{{ $serial->warranty_until?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
            @endif
        </dl>
        @can('inspect', $serial)
            @if (in_array($serial->status, [\App\Enums\SerialStatus::Returned, \App\Enums\SerialStatus::Quarantined], true))
                <div class="mt-4 flex flex-wrap items-end gap-2">
                    <div class="flex-1"><label for="i-note" class="form-label">{{ __('stock.serials.inspection_note') }}</label><input wire:model="note" id="i-note" type="text" class="form-input"></div>
                    <button type="button" wire:click="inspect" class="btn btn-primary" data-action="inspect">{{ __('stock.actions.inspect') }}</button>
                </div>
            @endif
        @endcan
    </header>

    <section class="card p-5">
        <h3 class="font-semibold text-ink">{{ __('stock.serials.history') }}</h3>
        <ol class="mt-3 divide-y divide-line text-sm" data-serial-history>
            @foreach ($serial->events as $event)
                <li class="flex flex-wrap items-baseline justify-between gap-2 py-2" wire:key="ev-{{ $event->id }}">
                    <span class="text-ink"><span class="font-semibold">{{ __('stock.events.'.$event->event) }}</span>@if ($event->to_status) · {{ \App\Enums\SerialStatus::tryFrom($event->to_status)?->label() }}@endif @if ($event->reason) · {{ $event->reason }}@endif · {{ $event->causer?->name ?? __('activity.system') }}</span>
                    <time class="text-xs text-muted">{{ $event->created_at?->translatedFormat($dateTimeFormat) }}</time>
                </li>
            @endforeach
        </ol>
    </section>
</div>
