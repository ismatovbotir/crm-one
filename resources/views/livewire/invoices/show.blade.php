<div class="space-y-6">
    @php
        $invoice = $this->invoice;
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
    @endphp

    <a href="{{ route('invoices.index') }}" wire:navigate class="link text-sm">&larr; {{ __('accounting.invoices.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="font-mono text-sm text-muted">{{ $invoice->type->label() }} · {{ $invoice->issued_on->translatedFormat($dateFormat) }}</p>
                <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ $invoice->number }}</h2>
                <p class="mt-1 text-sm text-ink">{{ $invoice->customer?->name }} · @can('view', $invoice->order)<a href="{{ route('orders.show', ['order' => $invoice->order, 'tab' => 'payments']) }}" wire:navigate class="link font-mono">{{ $invoice->order->number }}</a>@else<span class="font-mono">{{ $invoice->order?->number }}</span>@endcan</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-badge :tone="$invoice->status === \App\Enums\InvoiceStatus::Voided ? 'outline' : 'silver'">{{ $invoice->status->label() }}</x-badge>
                    @if ($invoice->void_requested_at && $invoice->status === \App\Enums\InvoiceStatus::Issued) <x-badge tone="danger-outline" icon="clock">{{ __('accounting.payment.void_requested') }}</x-badge> @endif
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                @can('print', $invoice)
                    <a href="{{ route('invoices.print', $invoice) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('accounting.actions.print') }}</a>
                @endcan
                @can('approveVoid', $invoice)
                    <button type="button" wire:click="approveVoid" class="btn btn-danger-outline" data-action="approve-void">{{ __('accounting.actions.approve_void') }}</button>
                @endcan
                @can('requestVoid', $invoice)
                    <button type="button" wire:click="$set('panel', 'void')" class="btn btn-danger-outline" data-action="void">{{ __('accounting.actions.request_void') }}</button>
                @endcan
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.total') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $invoice->money($invoice->gross_minor)->format() }}</dd></div>
            @if ($invoice->requested_amount_minor !== null)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.requested_amount') }}</dt><dd class="mt-1 text-ink">{{ $invoice->money($invoice->requested_amount_minor)->format() }}</dd></div>
            @endif
            @if ($invoice->due_on)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.due_on') }}</dt><dd class="mt-1 text-ink">{{ $invoice->due_on->translatedFormat($dateFormat) }}</dd></div>
            @endif
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.issued_by') }}</dt><dd class="mt-1 text-ink">{{ $invoice->issuer?->name }}</dd></div>
            @if ($invoice->void_reason)
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('accounting.fields.void_reason') }}</dt><dd class="mt-1 text-ink">{{ $invoice->void_reason }}</dd></div>
            @endif
        </dl>

        @if ($invoice->code_warnings)
            <div class="mt-4 rounded-md border border-line-strong bg-neutral-50 px-3 py-2 text-sm" data-code-warnings>
                <p class="font-semibold text-ink">{{ __('accounting.codes.issued_with_warnings') }}</p>
                <ul class="mt-1 list-disc pl-5 text-muted">
                    @foreach ($invoice->code_warnings as $warning)
                        <li wire:key="cw-{{ $loop->index }}">{{ $warning['line'] }}. {{ $warning['name'] }} — {{ __('accounting.codes.'.$warning['code']) }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </header>

    @if ($panel === 'void')
        <section class="card border-2 border-brand-600 p-5" data-panel="void">
            <h3 class="font-semibold text-ink">{{ __('accounting.panels.void_invoice') }}</h3>
            <label for="v-reason" class="form-label mt-3">{{ __('accounting.fields.reason') }}</label>
            <textarea wire:model="reason" id="v-reason" rows="2" class="form-input"></textarea>
            @error('reason') <p class="form-error">{{ $message }}</p> @enderror
            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="$set('panel', '')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="button" wire:click="requestVoid" class="btn btn-primary" data-confirm-panel>{{ __('accounting.panels.confirm_void') }}</button>
            </div>
        </section>
    @endif

    @can('updateEsf', $invoice)
        <form wire:submit="saveEsf" class="card grid gap-4 p-5 sm:grid-cols-3" novalidate>
            <div>
                <label for="e-id" class="form-label">{{ __('accounting.fields.esf_id') }}</label>
                <input wire:model="esfId" id="e-id" type="text" class="form-input">
            </div>
            <div>
                <label for="e-status" class="form-label">{{ __('accounting.fields.esf_status') }}</label>
                <select wire:model="esfStatus" id="e-status" class="form-input">
                    <option value="">—</option>
                    @foreach ($esfStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end justify-end"><button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button></div>
        </form>
    @endcan

    <section class="card overflow-x-auto p-5">
        <table class="min-w-full divide-y divide-line text-sm" data-invoice-lines>
            <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                <tr>
                    <th class="py-2 pr-3">#</th>
                    <th class="py-2 pr-3">{{ __('orders.lines.name') }}</th>
                    <th class="py-2 pr-3">{{ __('orders.lines.accountant_code') }}</th>
                    <th class="py-2 pr-3">{{ __('orders.lines.class_code') }}</th>
                    <th class="py-2 pr-3">{{ __('orders.lines.package_code') }}</th>
                    <th class="py-2 pr-3 text-right">{{ __('accounting.fields.quantity') }}</th>
                    <th class="py-2 text-right">{{ __('accounting.fields.amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @foreach ($invoice->lines as $line)
                    <tr wire:key="il-{{ $line->id }}">
                        <td class="py-2 pr-3">{{ $line->position }}</td>
                        <td class="py-2 pr-3">{{ $line->name }} <span class="block font-mono text-xs text-muted">{{ $line->sku }}</span></td>
                        <td class="py-2 pr-3 font-mono text-xs">{{ $line->accountant_code ?? '—' }}</td>
                        <td class="py-2 pr-3 font-mono text-xs">{{ $line->class_code ?? '—' }}</td>
                        <td class="py-2 pr-3 font-mono text-xs">{{ $line->package_code ?? '—' }}</td>
                        <td class="py-2 pr-3 text-right">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit_name }}</td>
                        <td class="py-2 text-right whitespace-nowrap">{{ $invoice->money($line->line_gross_minor)->format() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
