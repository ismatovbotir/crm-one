<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('accounting.invoices.intro') }}</p>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto] lg:items-end" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('accounting.invoices.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="type-filter" class="sr-only">{{ __('accounting.fields.document_type') }}</label>
                <select wire:model.live="type" id="type-filter" class="form-input">
                    <option value="">{{ __('accounting.invoices.any_type') }}</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
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
            <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model.live="voidRequests" class="size-4 accent-brand-600"> {{ __('accounting.invoices.void_requests') }}</label>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('accounting.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('accounting.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('accounting.fields.amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->invoices as $invoice)
                        <tr wire:key="inv-{{ $invoice->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('invoices.show', $invoice) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $invoice->number }}</a>
                                <span class="block text-xs text-muted">{{ $invoice->type->label() }} · {{ $invoice->issued_on->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }} · {{ $invoice->order?->number }}</span>
                            </td>
                            <td class="max-w-xs px-4 py-3"><span class="block truncate text-ink">{{ $invoice->customer?->name }}</span></td>
                            <td class="px-4 py-3">
                                <x-badge :tone="$invoice->status === \App\Enums\InvoiceStatus::Voided ? 'outline' : 'silver'">{{ $invoice->status->label() }}</x-badge>
                                @if ($invoice->void_requested_at && $invoice->status === \App\Enums\InvoiceStatus::Issued) <x-badge tone="danger-outline" icon="clock">{{ __('accounting.payment.void_requested') }}</x-badge> @endif
                                @if ($invoice->code_warnings) <x-badge tone="outline" icon="warning">{{ __('accounting.codes.with_warnings') }}</x-badge> @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap text-ink">{{ $invoice->money($invoice->requested_amount_minor ?? $invoice->gross_minor)->format() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-0"><x-empty-state :title="__('accounting.invoices.empty')" icon="orders" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->invoices->hasPages())
            <div class="border-t border-line p-4">{{ $this->invoices->links() }}</div>
        @endif
    </div>
</div>
