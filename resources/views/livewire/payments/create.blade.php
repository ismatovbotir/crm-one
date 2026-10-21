<div class="space-y-6">
    <a href="{{ route('payments.index') }}" wire:navigate class="link text-sm">&larr; {{ __('accounting.payments.back') }}</a>

    <x-flash />

    <form wire:submit="save" class="card space-y-5 p-6" novalidate data-payment-form>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="p-customer" class="form-label">{{ __('accounting.fields.customer') }}</label>
                <input x-data @keydown.enter.prevent wire:model.live.debounce.300ms="customerSearch" id="p-customer" type="search" class="form-input" placeholder="{{ __('accounting.payments.customer_search') }}" autocomplete="off">
                @if ($customerSearch !== '' && $customerId === '')
                    <ul class="mt-1 max-h-56 overflow-y-auto rounded-md border border-line bg-surface text-sm shadow-sm">
                        @foreach ($this->customerOptions as $option)
                            <li wire:key="co-{{ $option->id }}"><button type="button" wire:click="selectCustomer({{ $option->id }})" class="w-full px-3 py-2 text-left hover:bg-brand-50">{{ $option->name }} <span class="text-muted">{{ $option->tax_id }}</span></button></li>
                        @endforeach
                    </ul>
                @elseif ($customerId !== '')
                    <button type="button" wire:click="$set('customerId', '')" class="mt-1 text-xs link">{{ __('accounting.payments.change_customer') }}</button>
                @endif
                @error('customerId') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="p-amount" class="form-label">{{ __('accounting.fields.amount') }}</label>
                <div class="flex gap-2">
                    <input wire:model="amount" id="p-amount" type="text" inputmode="decimal" class="form-input">
                    <select wire:model.live="currency" class="form-input w-28" aria-label="{{ __('accounting.fields.currency') }}">
                        @foreach ($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                </div>
                @error('amount') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="p-date" class="form-label">{{ __('accounting.fields.paid_on') }}</label>
                <input wire:model="paidOn" id="p-date" type="date" class="form-input">
                @error('paidOn') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="p-method" class="form-label">{{ __('accounting.fields.method') }}</label>
                <select wire:model.live="method" id="p-method" class="form-input">
                    @foreach ($methods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($method !== 'cash')
                <div>
                    <label for="p-bank" class="form-label">{{ __('accounting.fields.bank_account') }}</label>
                    <select wire:model="bankAccountId" id="p-bank" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->bankAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->bank_name }} · {{ $account->account_number }}</option>
                        @endforeach
                    </select>
                    @error('bankAccountId') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-ref" class="form-label">{{ __('accounting.fields.reference_number') }}</label>
                    <input wire:model="referenceNumber" id="p-ref" type="text" class="form-input">
                    @error('referenceNumber') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div>
                <label for="p-payer" class="form-label">{{ __('accounting.fields.payer_name') }}</label>
                <input wire:model="payerName" id="p-payer" type="text" class="form-input">
                @error('payerName') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="p-payer-tax" class="form-label">{{ __('accounting.fields.payer_tax_id') }}</label>
                <input wire:model="payerTaxId" id="p-payer-tax" type="text" class="form-input">
            </div>
            <div class="sm:col-span-2">
                <label for="p-purpose" class="form-label">{{ __('accounting.fields.purpose') }}</label>
                <input wire:model="purpose" id="p-purpose" type="text" class="form-input">
            </div>
            <div class="sm:col-span-2">
                <label for="p-proof" class="form-label">{{ __('accounting.fields.proof') }}</label>
                <input wire:model="proof" id="p-proof" type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-input">
                <p class="mt-1 text-xs text-muted">{{ __('accounting.payments.proof_help') }}</p>
                @error('proof') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="p-note" class="form-label">{{ __('accounting.fields.note') }}</label>
                <textarea wire:model="note" id="p-note" rows="2" class="form-input"></textarea>
            </div>
        </div>

        @if ($duplicateOf !== '')
            <p class="rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-duplicate-warning>{{ __('accounting.payments.duplicate_confirm', ['number' => $duplicateOf]) }}</p>
        @endif

        <div class="flex justify-end gap-3">
            <a href="{{ route('payments.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ $duplicateOf !== '' ? __('accounting.payments.record_anyway') : __('accounting.payments.record') }}</button>
        </div>
    </form>
</div>
