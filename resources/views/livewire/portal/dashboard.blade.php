<div class="grid gap-6 lg:grid-cols-2" data-portal-dashboard>
    <section class="card" aria-labelledby="pd-offers">
        <h2 id="pd-offers" class="border-b border-line px-5 py-3 font-semibold text-ink">{{ __('portal.dashboard.open_offers') }}</h2>
        <ul class="divide-y divide-line">
            @forelse ($openOffers as $offer)
                <li class="flex items-center justify-between gap-2 px-5 py-3 text-sm" wire:key="po-{{ $offer->id }}">
                    <a href="{{ route('portal.offers.show', $offer) }}" wire:navigate class="link font-mono">{{ $offer->displayNumber() }}</a>
                    <span class="text-xs text-muted">{{ __('portal.offers.valid_until', ['date' => $offer->valid_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat())]) }}</span>
                </li>
            @empty
                <li class="px-5 py-4 text-sm text-muted">{{ __('portal.dashboard.no_open_offers') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card" aria-labelledby="pd-orders">
        <h2 id="pd-orders" class="border-b border-line px-5 py-3 font-semibold text-ink">{{ __('portal.dashboard.orders') }}</h2>
        <ul class="divide-y divide-line">
            @forelse ($orders as $order)
                <li class="flex items-center justify-between gap-2 px-5 py-3 text-sm" wire:key="pord-{{ $order->id }}">
                    <a href="{{ route('portal.orders.show', $order) }}" wire:navigate class="link font-mono">{{ $order->number }}</a>
                    <x-orders.status-badge :status="$order->status" />
                </li>
            @empty
                <li class="px-5 py-4 text-sm text-muted">{{ __('portal.dashboard.no_orders') }}</li>
            @endforelse
        </ul>
    </section>

    @if ($invoices->isNotEmpty())
        <section class="card lg:col-span-2" aria-labelledby="pd-invoices">
            <h2 id="pd-invoices" class="border-b border-line px-5 py-3 font-semibold text-ink">{{ __('portal.dashboard.invoices') }}</h2>
            <ul class="divide-y divide-line">
                @foreach ($invoices as $invoice)
                    <li class="flex items-center justify-between gap-2 px-5 py-3 text-sm" wire:key="pinv-{{ $invoice->id }}">
                        <a href="{{ route('portal.invoices.print', $invoice) }}" target="_blank" rel="noopener" class="link font-mono">{{ $invoice->number }}</a>
                        <span class="text-ink">{{ $invoice->money($invoice->gross_minor)?->format() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
