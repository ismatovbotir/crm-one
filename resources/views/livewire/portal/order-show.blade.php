<div class="space-y-6" data-portal-order>
    <a href="{{ route('portal.orders.index') }}" wire:navigate class="link text-sm">&larr; {{ __('portal.orders.title') }}</a>

    <header class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="font-mono text-sm text-muted">{{ $order->number }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <x-orders.status-badge :status="$order->status" />
                @if ($showPayments)
                    @php([$tone, $icon] = $order->payment_status->badge())
                    <x-badge :tone="$tone" :icon="$icon">{{ $order->payment_status->label() }}</x-badge>
                @endif
            </div>
            <p class="mt-2 text-sm text-ink">{{ __('portal.orders.total') }}: <span class="font-semibold">{{ $order->money($order->gross_minor)->format() }}</span></p>
            @if ($order->promised_delivery_on)<p class="text-sm text-ink">{{ __('portal.orders.promised') }}: {{ $order->promised_delivery_on->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</p>@endif
        </div>
        <a href="{{ route('portal.orders.print', $order) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('portal.print') }}</a>
    </header>

    <section class="card p-5">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><tr><th scope="col" class="py-2">#</th><th scope="col" class="py-2">{{ __('portal.orders.item') }}</th><th scope="col" class="py-2 text-right">{{ __('portal.orders.quantity') }}</th><th scope="col" class="py-2 text-right">{{ __('portal.orders.delivered') }}</th><th scope="col" class="py-2 text-right">{{ __('portal.orders.total') }}</th></tr></thead>
            <tbody class="divide-y divide-line">
                @foreach ($order->lines as $line)
                    <tr wire:key="pol-{{ $line->id }}">
                        <td class="py-2 text-muted">{{ $line->position }}</td>
                        <td class="py-2 text-ink">{{ $line->name }} <span class="font-mono text-xs text-muted">{{ $line->sku }}</span></td>
                        <td class="py-2 text-right text-ink">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit_name }}</td>
                        <td class="py-2 text-right text-ink">{{ \App\Support\Quantity::trimmed((string) $line->delivered_qty) }}</td>
                        <td class="py-2 text-right whitespace-nowrap text-ink">{{ $order->money($line->line_gross_minor)->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @if ($order->deliveries->isNotEmpty())
        <section class="card p-5" aria-labelledby="pod-title">
            <h2 id="pod-title" class="font-semibold text-ink">{{ __('portal.orders.deliveries') }}</h2>
            <ul class="mt-2 divide-y divide-line text-sm">
                @foreach ($order->deliveries as $delivery)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="pdl-{{ $delivery->id }}">
                        <span class="font-mono text-ink">{{ $delivery->number }} · {{ $delivery->delivered_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</span>
                        <span class="flex gap-3">
                            <a href="{{ route('portal.deliveries.print', $delivery) }}" target="_blank" rel="noopener" class="link">{{ __('portal.orders.delivery_note') }}</a>
                            <a href="{{ route('portal.deliveries.warranty', $delivery) }}" target="_blank" rel="noopener" class="link">{{ __('portal.orders.warranty_card') }}</a>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
