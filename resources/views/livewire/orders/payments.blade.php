<div class="space-y-5" data-order-payments>
    @php
        $order = $this->order;
        $user = auth()->user();
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
    @endphp

    <x-flash />

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-ink">
            {{ __('accounting.order.paid_of', ['paid' => $order->money($order->paid_minor)->format(), 'total' => $order->money($order->gross_minor)->format()]) }}
            @php([$tone, $icon] = $order->payment_status->badge())
            <x-badge :tone="$tone" :icon="$icon">{{ $order->payment_status->label() }}</x-badge>
        </p>
        <div class="flex flex-wrap gap-2">
            @can('amendSchedule', $order)
                <button type="button" wire:click="openPanel('terms')" class="btn btn-secondary btn-sm" data-action="terms">{{ __('accounting.actions.payment_terms') }}</button>
            @endcan
            @can('issueProforma', $order)
                <button type="button" wire:click="openPanel('proforma')" class="btn btn-secondary btn-sm" data-action="proforma">{{ __('accounting.actions.issue_proforma') }}</button>
            @endcan
            @can('issueReceipt', $order)
                <button type="button" wire:click="issueReceipt" wire:loading.attr="disabled" class="btn btn-primary btn-sm" data-action="issue-receipt">{{ __('accounting.actions.issue_receipt') }}</button>
            @endcan
            @can('registerTaxDocument', $order)
                <button type="button" wire:click="openPanel('tax')" class="btn btn-secondary btn-sm" data-action="tax">{{ __('accounting.actions.register_tax_document') }}</button>
            @endcan
            @can('close', $order)
                <button type="button" wire:click="closeOrder" class="btn btn-primary btn-sm" data-action="close">{{ __('accounting.actions.close_order') }}</button>
            @endcan
        </div>
    </div>

    @if ($panel !== '')
        <section class="rounded-md border-2 border-brand-600 p-4" data-panel="{{ $panel }}">
            <h4 class="font-semibold text-ink">{{ __('accounting.panels.'.$panel) }}</h4>

            @if ($panel === 'terms')
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="t-prepay" class="form-label">{{ __('accounting.fields.prepayment_percent') }}</label>
                        <input wire:model="prepaymentPercent" id="t-prepay" type="text" inputmode="decimal" class="form-input">
                        @error('prepaymentPercent') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-days" class="form-label">{{ __('accounting.fields.payment_days') }}</label>
                        <input wire:model="paymentDays" id="t-days" type="number" min="0" max="365" class="form-input">
                        @error('paymentDays') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="mt-2 text-xs text-muted">{{ __('accounting.panels.terms_help') }}</p>
            @endif

            @if (in_array($panel, ['proforma', 'tax'], true) && $this->codeProblems !== [])
                <div class="mt-3 rounded-md border border-line-strong bg-neutral-50 px-3 py-2 text-sm" data-code-problems>
                    <p class="font-semibold text-ink">{{ __('accounting.codes.missing_title') }}</p>
                    <ul class="mt-1 list-disc pl-5 text-muted">
                        @foreach ($this->codeProblems as $problem)
                            <li wire:key="cp-{{ $loop->index }}">{{ $problem['line'] }}. {{ $problem['name'] }} — {{ __('accounting.codes.'.$problem['code']) }} ({{ __('accounting.codes.mode_'.$problem['mode']) }})</li>
                        @endforeach
                    </ul>
                    <label class="mt-2 flex items-center gap-2 text-ink"><input type="checkbox" wire:model="acknowledgeCodes" class="size-4 accent-brand-600" data-ack-codes> {{ __('accounting.codes.acknowledge') }}</label>
                </div>
            @endif

            @if ($panel === 'proforma')
                <div class="mt-3 max-w-xs">
                    <label for="p-amount" class="form-label">{{ __('accounting.fields.requested_amount') }} ({{ $order->currency }})</label>
                    <input wire:model="requestedAmount" id="p-amount" type="text" inputmode="decimal" class="form-input" placeholder="{{ __('accounting.panels.requested_default') }}">
                    @error('requestedAmount') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($panel === 'tax')
                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label for="x-type" class="form-label">{{ __('accounting.fields.document_type') }}</label>
                        <select wire:model="tax.type" id="x-type" class="form-input">
                            @foreach ($taxTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="x-number" class="form-label">{{ __('accounting.fields.document_number') }}</label>
                        <input wire:model="tax.number" id="x-number" type="text" class="form-input">
                        @error('tax.number') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="x-date" class="form-label">{{ __('accounting.fields.document_date') }}</label>
                        <input wire:model="tax.issued_on" id="x-date" type="date" class="form-input">
                        @error('tax.issued_on') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="x-esf" class="form-label">{{ __('accounting.fields.esf_id') }}</label>
                        <input wire:model="tax.esf_id" id="x-esf" type="text" class="form-input">
                    </div>
                </div>
                <table class="mt-3 min-w-full text-sm">
                    <thead class="text-left text-xs text-muted uppercase"><tr><th class="py-1 pr-3">{{ __('orders.lines.name') }}</th><th class="py-1 pr-3 text-right">{{ __('accounting.order.invoiceable') }}</th><th class="py-1 text-right">{{ __('accounting.fields.quantity') }}</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($order->lines as $line)
                            <tr wire:key="tx-{{ $line->id }}">
                                <td class="py-1 pr-3">{{ $line->option_label }}. {{ $line->name }}</td>
                                <td class="py-1 pr-3 text-right">{{ \App\Support\Quantity::trimmed(bcsub((string) $line->delivered_qty, (string) $line->invoiced_qty, 3)) }}</td>
                                <td class="py-1 text-right"><input wire:model="taxLines.{{ $line->id }}" type="text" inputmode="decimal" class="form-input w-28 py-1 text-right" aria-label="{{ __('accounting.fields.quantity') }} {{ $line->name }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-muted">{{ __('accounting.panels.tax_help') }}</p>
            @endif

            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="closePanel" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                @php($submit = ['terms' => 'saveTerms', 'proforma' => 'issueProforma', 'tax' => 'registerTax'][$panel])
                <button type="button" wire:click="{{ $submit }}" class="btn btn-primary" wire:loading.attr="disabled" data-confirm-panel>{{ __('accounting.panels.confirm_'.$panel) }}</button>
            </div>
        </section>
    @endif

    <section>
        <h4 class="text-sm font-semibold text-ink">{{ __('accounting.order.schedule') }}</h4>
        <table class="mt-2 min-w-full divide-y divide-line text-sm" data-schedule>
            <thead class="text-left text-xs text-muted uppercase">
                <tr><th class="py-1 pr-3">{{ __('accounting.fields.kind') }}</th><th class="py-1 pr-3">{{ __('accounting.fields.due_on') }}</th><th class="py-1 pr-3 text-right">{{ __('accounting.fields.amount') }}</th><th class="py-1 pr-3 text-right">{{ __('accounting.order.covered') }}</th><th class="py-1 text-right">{{ __('accounting.order.outstanding') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($this->coverage as $row)
                    <tr wire:key="sch-{{ $row['item']->id }}" @class(['text-brand-800 font-semibold' => $row['days_overdue'] !== null])>
                        <td class="py-1 pr-3">{{ $row['item']->kind->label() }}@if ($row['item']->percent_bp) <span class="text-muted">· {{ rtrim(rtrim(bcdiv((string) $row['item']->percent_bp, '100', 2), '0'), '.') }}%</span>@endif</td>
                        <td class="py-1 pr-3">{{ $row['item']->due_on?->translatedFormat($dateFormat) ?? __('accounting.order.on_delivery') }}@if ($row['days_overdue'] !== null) <span class="block text-xs">{{ trans_choice('accounting.order.days_overdue', $row['days_overdue'], ['count' => $row['days_overdue']]) }}</span>@endif</td>
                        <td class="py-1 pr-3 text-right whitespace-nowrap">{{ $order->money($row['item']->amount_minor)->format() }}</td>
                        <td class="py-1 pr-3 text-right whitespace-nowrap">{{ $order->money($row['covered'])->format() }}</td>
                        <td class="py-1 text-right whitespace-nowrap">{{ $order->money($row['outstanding'])->format() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-muted">{{ __('accounting.order.no_schedule') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section>
        <h4 class="text-sm font-semibold text-ink">{{ __('accounting.order.allocations') }}</h4>
        <ul class="mt-2 divide-y divide-line text-sm" data-allocations>
            @forelse ($this->allocations as $allocation)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="al-{{ $allocation->id }}">
                    <span class="text-ink">
                        @can('view', $allocation->payment)
                            <a href="{{ route('payments.show', $allocation->payment) }}" wire:navigate class="link font-mono">{{ $allocation->payment->number }}</a>
                        @else
                            <span class="font-mono">{{ $allocation->payment->number }}</span>
                        @endcan
                        · {{ $allocation->payment->paid_on->translatedFormat($dateFormat) }}
                        @if ($allocation->isReversal()) · <span class="text-muted">{{ __('accounting.payment.reversal') }}</span>@endif
                    </span>
                    <span @class(['whitespace-nowrap', 'text-muted line-through' => $allocation->isReversal()])>{{ $order->money(abs($allocation->order_amount_minor))->format() }}</span>
                </li>
            @empty
                <li class="py-2 text-muted">{{ __('accounting.order.no_allocations') }}</li>
            @endforelse
        </ul>
    </section>

    <section>
        <h4 class="text-sm font-semibold text-ink">{{ __('accounting.order.invoices') }}</h4>
        <ul class="mt-2 divide-y divide-line text-sm" data-invoices>
            @forelse ($order->invoices()->get() as $invoice)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="inv-{{ $invoice->id }}">
                    <span class="text-ink">
                        <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="link font-mono">{{ $invoice->number }}</a>
                        · {{ $invoice->type->label() }} · {{ $invoice->issued_on->translatedFormat($dateFormat) }}
                        <x-badge :tone="$invoice->status === \App\Enums\InvoiceStatus::Voided ? 'outline' : 'silver'">{{ $invoice->status->label() }}</x-badge>
                    </span>
                    <span class="whitespace-nowrap">{{ $invoice->money($invoice->requested_amount_minor ?? $invoice->gross_minor)->format() }}</span>
                </li>
            @empty
                <li class="py-2 text-muted">{{ __('accounting.order.no_invoices') }}</li>
            @endforelse
        </ul>
    </section>
</div>
