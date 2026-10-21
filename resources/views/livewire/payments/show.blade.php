<div class="space-y-6">
    @php
        $payment = $this->payment;
        $user = auth()->user();
        $sensitive = $user->can('viewSensitive', $payment);
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
    @endphp

    <a href="{{ route('payments.index') }}" wire:navigate class="link text-sm">&larr; {{ __('accounting.payments.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $payment->number }} · {{ $payment->kind->label() }} · {{ $payment->source->label() }}</p>
                <h2 class="text-2xl font-semibold tracking-tight text-ink">@if ($payment->kind === \App\Enums\PaymentKind::Refund)−@endif{{ $payment->money()->format() }}</h2>
                <p class="mt-1 text-sm text-ink">
                    @if ($payment->customer)
                        @can('view', $payment->customer)<a href="{{ route('customers.show', $payment->customer) }}" wire:navigate class="link">{{ $payment->customer->name }}</a>@else{{ $payment->customer->name }}@endcan
                    @else
                        <span class="font-semibold text-brand-700">{{ __('accounting.payment.unmatched') }}</span>
                    @endif
                </p>
                <div class="mt-3"><x-accounting.payment-status :payment="$payment" /></div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2" data-payment-actions>
                @can('approve', $payment)
                    <button type="button" wire:click="approve" class="btn btn-primary" data-action="approve">{{ __('accounting.actions.approve') }}</button>
                    <button type="button" wire:click="openPanel('reject')" class="btn btn-secondary" data-action="reject">{{ __('accounting.actions.reject') }}</button>
                @endcan
                @can('allocate', $payment)
                    @if ($payment->unallocatedMinor() > 0 && $payment->customer_id)
                        <button type="button" wire:click="openPanel('allocate')" class="btn btn-primary" data-action="allocate">{{ __('accounting.actions.allocate') }}</button>
                    @endif
                @endcan
                @can('refund', $payment)
                    <button type="button" wire:click="openPanel('refund')" class="btn btn-secondary" data-action="refund">{{ __('accounting.actions.refund') }}</button>
                @endcan
                @can('approveVoid', $payment)
                    <button type="button" wire:click="approveVoid" class="btn btn-danger-outline" data-action="approve-void">{{ __('accounting.actions.approve_void') }}</button>
                @endcan
                @can('requestVoid', $payment)
                    <button type="button" wire:click="openPanel('void')" class="btn btn-danger-outline" data-action="void">{{ __('accounting.actions.request_void') }}</button>
                @endcan
                @can('resolve', $payment)
                    @if ($payment->customer_id === null && $payment->status !== \App\Enums\PaymentStatus::Voided)
                        <button type="button" wire:click="openPanel('link')" class="btn btn-primary" data-action="link">{{ __('accounting.actions.link_customer') }}</button>
                    @endif
                    @if ($payment->match_state === \App\Enums\MatchState::PossibleDuplicate)
                        <button type="button" wire:click="markSame" class="btn btn-secondary" data-action="same">{{ __('accounting.actions.same_payment') }}</button>
                        <button type="button" wire:click="markDistinct" class="btn btn-secondary" data-action="distinct">{{ __('accounting.actions.different_payment') }}</button>
                    @endif
                @endcan
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.paid_on') }}</dt><dd class="mt-1 text-ink">{{ $payment->paid_on->translatedFormat($dateFormat) }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.method') }}</dt><dd class="mt-1 text-ink">{{ $payment->method->label() }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.allocated') }}</dt><dd class="mt-1 text-ink">{{ $payment->money($payment->allocated_minor)->format() }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.unallocated') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $payment->money($payment->unallocatedMinor())->format() }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.payer_name') }}</dt><dd class="mt-1 text-ink">{{ $payment->payer_name }}@if ($payment->payer_tax_id) · {{ $payment->payer_tax_id }}@endif</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.reference_number') }}</dt><dd class="mt-1 text-ink">{{ $payment->reference_number ?? '—' }}</dd></div>
            @if ($sensitive)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.bank_account') }}</dt><dd class="mt-1 text-ink">{{ $payment->bankAccount ? $payment->bankAccount->bank_name.' · '.$payment->bankAccount->account_number : '—' }}</dd></div>
                @if ($payment->proof)
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.proof') }}</dt><dd class="mt-1"><a href="{{ route('attachments.download', $payment->proof) }}" class="link">{{ $payment->proof->original_name }}</a></dd></div>
                @endif
            @endif
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.recorded_by') }}</dt><dd class="mt-1 text-ink">{{ $payment->recorder?->name ?? '1C' }}</dd></div>
            @if ($payment->onec_number || $payment->onec_acked_at)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">1C</dt><dd class="mt-1 text-ink">{{ $payment->onec_number ?? '—' }}@if ($payment->onec_acked_at) · {{ $payment->onec_acked_at->translatedFormat($dateTimeFormat) }}@endif</dd></div>
            @endif
            @if ($payment->refundOf)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.refund_of') }}</dt><dd class="mt-1"><a href="{{ route('payments.show', $payment->refundOf) }}" wire:navigate class="link font-mono">{{ $payment->refundOf->number }}</a></dd></div>
            @endif
            @if ($payment->duplicateOf)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.duplicate_of') }}</dt><dd class="mt-1"><a href="{{ route('payments.show', $payment->duplicateOf) }}" wire:navigate class="link font-mono">{{ $payment->duplicateOf->number }}</a></dd></div>
            @endif
            @if ($payment->purpose)
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.purpose') }}</dt><dd class="mt-1 text-ink">{{ $payment->purpose }}</dd></div>
            @endif
            @if ($payment->void_reason)
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.void_reason') }}</dt><dd class="mt-1 text-ink">{{ $payment->void_reason }}</dd></div>
            @endif
            @if ($payment->reject_reason)
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.reject_reason') }}</dt><dd class="mt-1 text-ink">{{ $payment->reject_reason }}</dd></div>
            @endif
        </dl>
    </header>

    @if ($panel !== '')
        <section class="card border-2 border-brand-600 p-5" data-panel="{{ $panel }}">
            <h3 class="font-semibold text-ink">{{ __('accounting.panels.'.$panel) }}</h3>

            @if ($panel === 'allocate')
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label for="a-order" class="form-label">{{ __('accounting.fields.order') }}</label>
                        <select wire:model.live="orderId" id="a-order" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->payableOrders as $order)
                                <option value="{{ $order->id }}">{{ $order->number }} · {{ $order->money($order->gross_minor)->format() }} · {{ $order->payment_status->label() }}</option>
                            @endforeach
                        </select>
                        @error('orderId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="a-amount" class="form-label">{{ __('accounting.fields.amount') }} ({{ $payment->currency }})</label>
                        <input wire:model="amount" id="a-amount" type="text" inputmode="decimal" class="form-input">
                        @error('amount') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    @php($selectedOrder = $this->payableOrders->firstWhere('id', (int) $orderId))
                    @if ($selectedOrder && $selectedOrder->currency !== $payment->currency)
                        <div>
                            <label for="a-fx" class="form-label">{{ __('accounting.fields.fx_rate') }} ({{ $selectedOrder->currency }}/{{ $payment->currency }})</label>
                            <input wire:model="fxRate" id="a-fx" type="text" inputmode="decimal" class="form-input">
                            @error('fxRate') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    @if ($selectedOrder && $selectedOrder->invoices()->where('status', 'issued')->exists())
                        <div class="sm:col-span-2">
                            <label for="a-invoice" class="form-label">{{ __('accounting.fields.invoice') }}</label>
                            <select wire:model="invoiceId" id="a-invoice" class="form-input">
                                <option value="">—</option>
                                @foreach ($selectedOrder->invoices()->where('status', 'issued')->get() as $invoice)
                                    <option value="{{ $invoice->id }}">{{ $invoice->number }} · {{ $invoice->type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
                @if ($this->payableOrders->isEmpty())
                    <p class="mt-3 text-sm text-muted">{{ __('accounting.payment.no_payable_orders') }}</p>
                @endif
            @endif

            @if ($panel === 'refund')
                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="r-amount" class="form-label">{{ __('accounting.fields.amount') }} ({{ $payment->currency }})</label>
                        <input wire:model="refund.amount" id="r-amount" type="text" inputmode="decimal" class="form-input">
                        @error('refund.amount') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="r-date" class="form-label">{{ __('accounting.fields.paid_on') }}</label>
                        <input wire:model="refund.paid_on" id="r-date" type="date" class="form-input">
                    </div>
                    <div>
                        <label for="r-method" class="form-label">{{ __('accounting.fields.method') }}</label>
                        <select wire:model="refund.method" id="r-method" class="form-input">
                            @foreach ($methods as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="r-ref" class="form-label">{{ __('accounting.fields.reference_number') }}</label>
                        <input wire:model="refund.reference_number" id="r-ref" type="text" class="form-input">
                    </div>
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('accounting.panels.refund_help') }}</p>
            @endif

            @if ($panel === 'link')
                <div class="mt-4">
                    <label for="l-search" class="form-label">{{ __('accounting.fields.customer') }}</label>
                    <input wire:model.live.debounce.300ms="customerSearch" id="l-search" type="search" class="form-input" placeholder="{{ __('accounting.payments.customer_search') }}">
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($this->customerOptions as $option)
                            <li class="flex items-center justify-between py-2" wire:key="lc-{{ $option->id }}">
                                <span>{{ $option->name }} <span class="text-muted">{{ $option->tax_id }}</span></span>
                                <button type="button" wire:click="linkCustomer({{ $option->id }})" class="btn btn-secondary btn-sm">{{ __('accounting.actions.link') }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if (in_array($panel, ['reject', 'void', 'deallocate'], true))
                <div class="mt-4">
                    <label for="x-reason" class="form-label">{{ __('accounting.fields.reason') }}</label>
                    <textarea wire:model="reason" id="x-reason" rows="2" class="form-input"></textarea>
                    @error('reason') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="closePanel" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                @if ($panel !== 'link')
                    @php($submit = ['allocate' => 'allocate', 'reject' => 'reject', 'void' => 'requestVoid', 'refund' => 'saveRefund', 'deallocate' => 'deallocate'][$panel])
                    <button type="button" wire:click="{{ $submit }}" class="btn btn-primary" wire:loading.attr="disabled" data-confirm-panel>{{ __('accounting.panels.confirm_'.$panel) }}</button>
                @endif
            </div>
        </section>
    @endif

    <section class="card p-5">
        <h3 class="font-semibold text-ink">{{ __('accounting.order.allocations') }}</h3>
        <ul class="mt-3 divide-y divide-line text-sm" data-allocations>
            @forelse ($this->allocations as $allocation)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="pa-{{ $allocation->id }}">
                    <span class="text-ink">
                        @can('view', $allocation->order)<a href="{{ route('orders.show', ['order' => $allocation->order, 'tab' => 'payments']) }}" wire:navigate class="link font-mono">{{ $allocation->order->number }}</a>@else<span class="font-mono">{{ $allocation->order->number }}</span>@endcan
                        @if ($allocation->invoice) · {{ $allocation->invoice->number }} @endif
                        · {{ $allocation->created_at?->translatedFormat($dateTimeFormat) }} · {{ $allocation->creator?->name ?? '1C' }}
                        @if ($allocation->isReversal()) · <span class="text-muted">{{ __('accounting.payment.reversal') }}: {{ $allocation->reason === 'payment_voided' || $allocation->reason === 'deleted_in_1c' ? __('accounting.reasons.'.$allocation->reason) : $allocation->reason }}</span>@endif
                        @if ($allocation->fx_rate) · <span class="text-muted">{{ __('accounting.payment.fx', ['rate' => rtrim(rtrim((string) $allocation->fx_rate, '0'), '.'), 'amount' => $allocation->order->money(abs($allocation->order_amount_minor))->format()]) }}</span>@endif
                    </span>
                    <span class="flex items-center gap-3">
                        <span @class(['whitespace-nowrap', 'text-muted' => $allocation->isReversal() || $allocation->reversal])>{{ $allocation->amount_minor < 0 ? '−' : '' }}{{ $payment->money(abs($allocation->amount_minor))->format() }}</span>
                        @if (! $allocation->isReversal() && ! $allocation->reversal)
                            @can('allocate', $payment)
                                <button type="button" wire:click="openPanel('deallocate', {{ $allocation->id }})" class="btn btn-secondary btn-sm" data-action="deallocate">{{ __('accounting.actions.deallocate') }}</button>
                            @endcan
                        @endif
                    </span>
                </li>
            @empty
                <li class="py-2 text-muted">{{ __('accounting.order.no_allocations') }}</li>
            @endforelse
        </ul>
    </section>

    @if ($sensitive)
        <section class="card p-5">
            <h3 class="font-semibold text-ink">{{ __('accounting.payment.ledger') }}</h3>
            <ul class="mt-3 divide-y divide-line text-sm" data-ledger>
                @foreach ($this->entries as $entry)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="le-{{ $entry->id }}">
                        <span class="text-ink">{{ $entry->entry_type->label() }} · {{ $entry->occurred_on->translatedFormat($dateFormat) }} · {{ $entry->causer?->name ?? strtoupper($entry->source) }}</span>
                        <span class="whitespace-nowrap font-mono">{{ $entry->amount_minor < 0 ? '−' : '' }}{{ \App\Support\Money::of(abs($entry->amount_minor), $entry->currency)->format() }}</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
