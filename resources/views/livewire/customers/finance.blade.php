<div class="space-y-6" data-customer-finance>
    @php
        $customer = $this->customer;
        $canDetails = auth()->user()->can('manageFinanceDetails', $customer);
        $canCredit = auth()->user()->can('manageCredit', $customer);
        $exposure = $this->exposure;
    @endphp

    <x-flash />

    <section class="card p-5">
        <h3 class="font-semibold text-ink">{{ __('customers.finance.credit_title') }}</h3>
        <p class="mt-2 text-sm text-ink" data-exposure>
            @if ($exposure['limit'] !== null)
                {{ __('customers.finance.exposure_of_limit', ['exposure' => \App\Support\Money::of($exposure['exposure'], $exposure['currency'])->format(), 'limit' => \App\Support\Money::of($exposure['limit'], $exposure['currency'])->format()]) }}
            @else
                {{ __('customers.finance.no_limit') }}
            @endif
            @if ($customer->manual_hold)
                <x-badge tone="red" icon="lock">{{ __('customers.finance.on_manual_hold') }}</x-badge> <span class="text-muted">{{ $customer->manual_hold_reason }}</span>
            @endif
        </p>
        @if ($canCredit)
            <form wire:submit="saveCredit" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" novalidate>
                <div>
                    <label for="c-limit" class="form-label">{{ __('customers.finance.credit_limit') }}</label>
                    <input wire:model="credit.credit_limit" id="c-limit" type="text" inputmode="decimal" class="form-input" placeholder="{{ __('customers.finance.credit_limit_hint') }}">
                    @error('credit.credit_limit') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="c-currency" class="form-label">{{ __('customers.finance.credit_limit_currency') }}</label>
                    <select wire:model="credit.credit_limit_currency" id="c-currency" class="form-input">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}">{{ $currency }}</option>
                        @endforeach
                    </select>
                    @error('credit.credit_limit_currency') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink"><input type="checkbox" wire:model.live="credit.manual_hold" class="size-4 accent-brand-600"> {{ __('customers.finance.manual_hold') }}</label>
                <div>
                    <label for="c-reason" class="form-label">{{ __('customers.finance.manual_hold_reason') }}</label>
                    <input wire:model="credit.manual_hold_reason" id="c-reason" type="text" class="form-input" @disabled(! $credit['manual_hold'])>
                    @error('credit.manual_hold_reason') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end sm:col-span-2 lg:col-span-4">
                    <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
                </div>
            </form>
        @endif
    </section>

    <section class="card p-5">
        <h3 class="font-semibold text-ink">{{ __('customers.finance.details_title') }}</h3>
        <p class="mt-1 text-xs text-muted">{{ __('customers.finance.details_help') }}</p>
        <form wire:submit="saveDetails" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" novalidate>
            @foreach (['vat_registration_code', 'oked', 'signatory_name', 'signatory_position', 'signatory_basis'] as $field)
                <div wire:key="fd-{{ $field }}">
                    <label for="fd-{{ $field }}" class="form-label">{{ __('customers.finance.'.$field) }}</label>
                    <input wire:model="details.{{ $field }}" id="fd-{{ $field }}" type="text" class="form-input" @disabled(! $canDetails)>
                    @error('details.'.$field) <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endforeach
            @if ($canDetails)
                <div class="flex items-end justify-end"><button type="submit" class="btn btn-primary">{{ __('common.save') }}</button></div>
            @endif
        </form>
    </section>

    <section class="card p-5">
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-ink">{{ __('customers.finance.bank_accounts') }}</h3>
            @if ($canDetails)
                <button type="button" wire:click="editBank" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> {{ __('customers.finance.add_bank') }}</button>
            @endif
        </div>
        @if ($editing === 'bank')
            <form wire:submit="saveBank" class="mt-4 grid gap-4 rounded-md border border-line p-4 sm:grid-cols-2 lg:grid-cols-4" novalidate>
                <div class="sm:col-span-2">
                    <label for="cb-name" class="form-label">{{ __('settings.company.fields.bank_name') }}</label>
                    <input wire:model="bank.bank_name" id="cb-name" type="text" class="form-input">
                    @error('bank.bank_name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cb-mfo" class="form-label">{{ __('settings.company.fields.mfo') }}</label>
                    <input wire:model="bank.mfo" id="cb-mfo" type="text" inputmode="numeric" class="form-input">
                    @error('bank.mfo') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="cb-currency" class="form-label">{{ __('settings.company.fields.currency') }}</label>
                    <select wire:model="bank.currency" id="cb-currency" class="form-input">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}">{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="cb-account" class="form-label">{{ __('settings.company.fields.account_number') }}</label>
                    <input wire:model="bank.account_number" id="cb-account" type="text" inputmode="numeric" class="form-input font-mono">
                    @error('bank.account_number') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink"><input type="checkbox" wire:model="bank.is_default" class="size-4 accent-brand-600"> {{ __('settings.company.fields.is_default') }}</label>
                <div class="flex items-end justify-end gap-2">
                    <button type="button" wire:click="cancelEdit" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
                </div>
            </form>
        @endif
        <ul class="mt-4 divide-y divide-line">
            @forelse ($this->bankAccounts as $account)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="cbank-{{ $account->id }}">
                    <span class="text-ink"><span class="font-semibold">{{ $account->bank_name }}</span> · {{ __('settings.company.fields.mfo') }} {{ $account->mfo }} · <span class="font-mono">{{ $account->account_number }}</span> · {{ $account->currency }}
                        @if ($account->is_default) <x-badge tone="dark" icon="check">{{ __('settings.company.default') }}</x-badge> @endif
                    </span>
                    @if ($canDetails)
                        <span class="flex gap-2">
                            <button type="button" wire:click="editBank({{ $account->id }})" class="btn btn-secondary btn-sm">{{ __('common.edit') }}</button>
                            <button type="button" wire:click="removeBank({{ $account->id }})" wire:confirm="{{ __('customers.finance.remove_bank_confirm') }}" class="btn btn-danger-outline btn-sm">{{ __('customers.finance.remove_bank') }}</button>
                        </span>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-muted">{{ __('customers.finance.no_banks') }}</li>
            @endforelse
        </ul>
    </section>

    @if ($this->contacts->isNotEmpty())
        <section class="card p-5">
            <h3 class="font-semibold text-ink">{{ __('customers.finance.contact_roles') }}</h3>
            <ul class="mt-3 divide-y divide-line">
                @foreach ($this->contacts as $contact)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2 text-sm" wire:key="crole-{{ $contact->id }}">
                        <span class="text-ink">{{ $contact->fullName() }} <span class="text-muted">{{ $contact->position }}</span></span>
                        <span class="flex gap-4">
                            @foreach (['is_billing_contact', 'is_signatory'] as $flag)
                                <label class="flex items-center gap-1 text-xs text-ink">
                                    <input type="checkbox" @checked($contact->{$flag}) wire:click="toggleContactFlag({{ $contact->id }}, '{{ $flag }}')" class="size-4 accent-brand-600" @disabled(! $canDetails)>
                                    {{ __('customers.finance.'.$flag) }}
                                </label>
                            @endforeach
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card p-5">
            <h3 class="font-semibold text-ink">{{ __('customers.finance.orders') }}</h3>
            <ul class="mt-3 divide-y divide-line">
                @forelse ($this->orders as $order)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="co-{{ $order->id }}">
                        <a href="{{ route('orders.show', $order) }}" wire:navigate class="link font-mono">{{ $order->number }}</a>
                        <x-orders.status-badge :status="$order->status" />
                        <span class="text-ink">{{ $order->money($order->gross_minor)->format() }}</span>
                    </li>
                @empty
                    <li class="py-2 text-sm text-muted">{{ __('customers.finance.no_orders') }}</li>
                @endforelse
            </ul>
        </section>
        <section class="card p-5">
            <h3 class="font-semibold text-ink">{{ __('customers.finance.agreements') }}</h3>
            <ul class="mt-3 divide-y divide-line">
                @forelse ($this->agreements as $agreement)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm" wire:key="ca-{{ $agreement->id }}">
                        <a href="{{ route('agreements.show', $agreement) }}" wire:navigate class="link font-mono">{{ $agreement->number }}</a>
                        <span class="text-muted">{{ $agreement->kind->label() }}</span>
                        <x-agreements.status-badge :status="$agreement->status" />
                    </li>
                @empty
                    <li class="py-2 text-sm text-muted">{{ __('customers.finance.no_agreements') }}</li>
                @endforelse
            </ul>
            @can('create', \App\Models\Agreement::class)
                @unless ($customer->trashed())
                    <button type="button" wire:click="generateFramework" class="btn btn-secondary btn-sm mt-3" data-action="new-framework">{{ __('customers.finance.new_framework') }}</button>
                @endunless
            @endcan
        </section>
    </div>
</div>
