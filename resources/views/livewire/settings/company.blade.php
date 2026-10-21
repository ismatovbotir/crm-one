<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('settings.company.intro') }}</p>

    <x-flash />

    <form wire:submit="saveProfile" class="card space-y-5 p-5" novalidate data-company-profile>
        <h3 class="font-semibold text-ink">{{ __('settings.company.profile') }}</h3>
        @foreach (\App\Support\CompanyProfile::LOCALIZED as $field)
            <fieldset class="grid gap-3 sm:grid-cols-3" wire:key="field-{{ $field }}">
                <legend class="form-label sm:col-span-3">{{ __('settings.company.fields.'.$field) }}</legend>
                @foreach ($locales as $locale)
                    <div>
                        <label for="p-{{ $field }}-{{ $locale->value }}" class="text-xs text-muted">{{ $locale->label() }}</label>
                        <input wire:model="profile.{{ $field }}.{{ $locale->value }}" id="p-{{ $field }}-{{ $locale->value }}" type="text" class="form-input">
                        @error('profile.'.$field.'.'.$locale->value) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </fieldset>
        @endforeach
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (\App\Support\CompanyProfile::PLAIN as $field)
                <div wire:key="plain-{{ $field }}">
                    <label for="p-{{ $field }}" class="form-label">{{ __('settings.company.fields.'.$field) }}</label>
                    <input wire:model="profile.{{ $field }}" id="p-{{ $field }}" type="{{ $field === 'email' ? 'email' : 'text' }}" class="form-input">
                    @error('profile.'.$field) <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </div>
        <div class="flex justify-end">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button>
        </div>
    </form>

    <section class="card p-5" data-company-banks>
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-ink">{{ __('settings.company.bank_accounts') }}</h3>
            <button type="button" wire:click="editBank" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> {{ __('settings.company.add_bank') }}</button>
        </div>
        @if ($editing === 'bank')
            <form wire:submit="saveBank" class="mt-4 grid gap-4 rounded-md border border-line p-4 sm:grid-cols-2 lg:grid-cols-4" novalidate>
                <div class="sm:col-span-2">
                    <label for="b-name" class="form-label">{{ __('settings.company.fields.bank_name') }}</label>
                    <input wire:model="bank.bank_name" id="b-name" type="text" class="form-input">
                    @error('bank.bank_name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="b-mfo" class="form-label">{{ __('settings.company.fields.mfo') }}</label>
                    <input wire:model="bank.mfo" id="b-mfo" type="text" inputmode="numeric" class="form-input">
                    @error('bank.mfo') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="b-currency" class="form-label">{{ __('settings.company.fields.currency') }}</label>
                    <select wire:model="bank.currency" id="b-currency" class="form-input">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}">{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label for="b-account" class="form-label">{{ __('settings.company.fields.account_number') }}</label>
                    <input wire:model="bank.account_number" id="b-account" type="text" inputmode="numeric" class="form-input font-mono">
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
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="bank-{{ $account->id }}">
                    <span @class(['text-ink', 'text-muted line-through' => ! $account->is_active])>
                        <span class="font-semibold">{{ $account->bank_name }}</span> · {{ __('settings.company.fields.mfo') }} {{ $account->mfo }} · <span class="font-mono">{{ $account->account_number }}</span> · {{ $account->currency }}
                        @if ($account->is_default) <x-badge tone="dark" icon="check">{{ __('settings.company.default') }}</x-badge> @endif
                    </span>
                    <span class="flex gap-2">
                        <button type="button" wire:click="editBank({{ $account->id }})" class="btn btn-secondary btn-sm">{{ __('common.edit') }}</button>
                        <button type="button" wire:click="toggleBank({{ $account->id }})" class="btn btn-secondary btn-sm">{{ $account->is_active ? __('settings.company.deactivate') : __('settings.company.activate') }}</button>
                    </span>
                </li>
            @empty
                <li class="py-3 text-sm text-muted">{{ __('settings.company.no_banks') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card p-5" data-company-signatories>
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-semibold text-ink">{{ __('settings.company.signatories') }}</h3>
            <button type="button" wire:click="editSignatory" class="btn btn-secondary btn-sm"><x-icon name="plus" class="size-4" /> {{ __('settings.company.add_signatory') }}</button>
        </div>
        <p class="mt-1 text-xs text-muted">{{ __('settings.company.signatories_help') }}</p>
        @if ($editing === 'signatory')
            <form wire:submit="saveSignatory" class="mt-4 grid gap-4 rounded-md border border-line p-4 sm:grid-cols-2" novalidate>
                <div>
                    <label for="s-name" class="form-label">{{ __('settings.company.fields.full_name') }}</label>
                    <input wire:model="signatory.full_name" id="s-name" type="text" class="form-input">
                    @error('signatory.full_name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="s-position" class="form-label">{{ __('settings.company.fields.position') }}</label>
                    <input wire:model="signatory.position" id="s-position" type="text" class="form-input">
                    @error('signatory.position') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="s-basis" class="form-label">{{ __('settings.company.fields.basis') }}</label>
                    <input wire:model="signatory.basis" id="s-basis" type="text" class="form-input" placeholder="{{ __('settings.company.basis_hint') }}">
                </div>
                <div>
                    <label for="s-valid" class="form-label">{{ __('settings.company.fields.valid_until') }}</label>
                    <input wire:model="signatory.valid_until" id="s-valid" type="date" class="form-input">
                    @error('signatory.valid_until') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" wire:model="signatory.is_default" class="size-4 accent-brand-600"> {{ __('settings.company.fields.is_default') }}</label>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="cancelEdit" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
                </div>
            </form>
        @endif
        <ul class="mt-4 divide-y divide-line">
            @forelse ($this->signatories as $person)
                <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="sig-{{ $person->id }}">
                    <span @class(['text-ink', 'text-muted line-through' => ! $person->is_active])>
                        <span class="font-semibold">{{ $person->full_name }}</span> · {{ $person->position }}@if ($person->basis) · {{ $person->basis }}@endif
                        @if ($person->valid_until) · {{ __('settings.company.valid_until', ['date' => $person->valid_until->translatedFormat(\App\Enums\Locale::current()->dateFormat())]) }} @endif
                        @if ($person->is_default) <x-badge tone="dark" icon="check">{{ __('settings.company.default') }}</x-badge> @endif
                    </span>
                    <span class="flex gap-2">
                        <button type="button" wire:click="editSignatory({{ $person->id }})" class="btn btn-secondary btn-sm">{{ __('common.edit') }}</button>
                        <button type="button" wire:click="toggleSignatory({{ $person->id }})" class="btn btn-secondary btn-sm">{{ $person->is_active ? __('settings.company.deactivate') : __('settings.company.activate') }}</button>
                    </span>
                </li>
            @empty
                <li class="py-3 text-sm text-muted">{{ __('settings.company.no_signatories') }}</li>
            @endforelse
        </ul>
    </section>
</div>
