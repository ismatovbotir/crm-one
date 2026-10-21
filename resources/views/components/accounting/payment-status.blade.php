@props(['payment'])

{{-- Payment status (Spec 06 6.7) with the 1C and matching markers; text + icon, never colour alone. --}}
@php([$tone, $icon] = $payment->status->badge())
<div class="flex flex-wrap gap-1" data-payment-status="{{ $payment->status->value }}">
    <x-badge :tone="$tone" :icon="$icon">{{ $payment->status === \App\Enums\PaymentStatus::Recorded ? __('accounting.payment.pending_1c') : $payment->status->label() }}</x-badge>
    @if ($payment->match_state === \App\Enums\MatchState::PossibleDuplicate)
        <x-badge tone="red" icon="warning">{{ __('accounting.match_states.possible_duplicate') }}</x-badge>
    @elseif ($payment->match_state === \App\Enums\MatchState::NeedsReallocation)
        <x-badge tone="danger-outline" icon="warning">{{ __('accounting.match_states.needs_reallocation') }}</x-badge>
    @endif
    @if ($payment->void_requested_at && $payment->status !== \App\Enums\PaymentStatus::Voided)
        <x-badge tone="danger-outline" icon="clock">{{ __('accounting.payment.void_requested') }}</x-badge>
    @endif
    @if ($payment->onec_posted === false)
        <x-badge tone="outline">{{ __('accounting.payment.unposted_in_1c') }}</x-badge>
    @endif
    @if ($payment->posted_in_locked_period)
        <x-badge tone="outline" icon="lock">{{ __('accounting.payment.locked_period') }}</x-badge>
    @endif
</div>
