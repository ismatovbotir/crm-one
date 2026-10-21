@props(['order'])

{{-- Payment signal for the warehouse (Spec 06 section 29): text + icon, never colour alone. --}}
@php
    $signal = $order->paymentSignal();
    [$tone, $icon] = match ($signal['state']) {
        'paid' => ['dark', 'check'],
        'released', 'credit_terms' => ['silver', 'check'],
        'partial' => ['outline', 'clock'],
        default => ['outline', 'warning'],
    };
@endphp
<span class="inline-flex flex-col gap-0.5" data-payment-signal="{{ $signal['state'] }}">
    <x-badge :tone="$tone" :icon="$icon">{{ __('orders.payment_signal.'.$signal['state'], ['paid' => $order->money($signal['paid_minor'])->format(), 'total' => $order->money($order->gross_minor)->format(), 'user' => $signal['released_by'] ?? '—']) }}</x-badge>
    @if ($signal['state'] === 'partial' || ($signal['state'] === 'released' && $signal['paid_minor'] > 0))
        <span class="text-xs text-muted">{{ __('orders.payment_signal.paid_of', ['paid' => $order->money($signal['paid_minor'])->format(), 'total' => $order->money($order->gross_minor)->format()]) }}</span>
    @endif
</span>
