<div class="space-y-6">
    <a href="{{ route('offers.index') }}" wire:navigate class="link text-sm">&larr; {{ __('offers.back') }}</a>

    <form wire:submit="save" class="card" novalidate>
        <div class="grid gap-6 p-6 lg:grid-cols-2">
            <div class="space-y-3">
                <label for="customer-search" class="form-label">{{ __('offers.fields.customer') }}</label>
                @if ($this->customer)
                    <div class="flex items-start justify-between gap-3 rounded-md border border-line bg-neutral-50 px-3 py-2" data-selected-customer>
                        <div>
                            <p class="font-semibold text-ink">{{ $this->customer->name }}</p>
                            <p class="text-xs text-muted">{{ collect([$this->customer->tax_id, $this->customer->city])->filter()->join(' · ') }}</p>
                        </div>
                        <button type="button" wire:click="$set('customerId', null)" class="btn btn-secondary px-3 py-1.5">{{ __('offers.change_customer') }}</button>
                    </div>
                @else
                    <input wire:model.live.debounce.300ms="customerSearch" id="customer-search" type="search" placeholder="{{ __('offers.customer_search') }}" class="form-input" autocomplete="off">
                    <ul class="max-h-72 divide-y divide-line overflow-y-auto rounded-md border border-line" role="listbox" aria-label="{{ __('offers.fields.customer') }}">
                        @forelse ($this->customers as $option)
                            <li wire:key="cust-{{ $option->id }}">
                                <button type="button" wire:click="selectCustomer({{ $option->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-brand-50/40">
                                    <span class="font-medium text-ink">{{ $option->name }}</span>
                                    <span class="font-mono text-xs text-muted">{{ $option->tax_id }}</span>
                                </button>
                            </li>
                        @empty
                            <li class="px-3 py-3 text-sm text-muted">{{ __('offers.no_customers') }}</li>
                        @endforelse
                    </ul>
                @endif
                @error('customerId') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid content-start gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="contact" class="form-label">{{ __('offers.fields.contact') }}</label>
                    <select wire:model="contactId" id="contact" class="form-input" @disabled(! $this->customer)>
                        <option value="">{{ __('offers.no_contact') }}</option>
                        @foreach ($this->contacts as $contact)
                            <option value="{{ $contact->id }}" wire:key="contact-{{ $contact->id }}">{{ $contact->fullName() }}{{ $contact->email ? ' · '.$contact->email : '' }}</option>
                        @endforeach
                    </select>
                    @error('contactId') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="currency" class="form-label">{{ __('offers.fields.currency') }}</label>
                    <select wire:model="currency" id="currency" class="form-input">
                        @foreach ($currencies as $code)
                            <option value="{{ $code }}">{{ $code }}</option>
                        @endforeach
                    </select>
                    @error('currency') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="valid-until" class="form-label">{{ __('offers.fields.valid_until') }}</label>
                    <input wire:model="validUntil" id="valid-until" type="date" class="form-input">
                    @error('validUntil') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-3 border-t border-line bg-neutral-50 px-6 py-4">
            <a href="{{ route('offers.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('offers.create_draft') }}</button>
        </div>
    </form>
</div>
