            <section class="card" aria-labelledby="company-heading">
                <h2 id="company-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('customers.sections.company') }}</h2>
                <fieldset @disabled(! $general) class="grid gap-5 p-6 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label for="name" class="form-label">{{ __('customers.fields.name') }}</label>
                        <input wire:model.live.debounce.400ms="name" id="name" type="text" autocomplete="off" class="form-input" maxlength="255">
                        @error('name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="legal_name" class="form-label">{{ __('customers.fields.legal_name') }}</label>
                        <input wire:model="legal_name" id="legal_name" type="text" class="form-input" maxlength="255">
                        @error('legal_name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="legal_type" class="form-label">{{ __('customers.fields.legal_type') }}</label>
                        <select wire:model="legal_type" id="legal_type" class="form-input">
                            @foreach ($legalTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('legal_type') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="tax_id" class="form-label">{{ __('customers.fields.tax_id') }}</label>
                        <input wire:model.live.debounce.400ms="tax_id" id="tax_id" type="text" inputmode="numeric" class="form-input font-mono" maxlength="32" @disabled($taxLocked)>
                        @if ($taxLocked)
                            <p class="mt-1 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('customers.tax_id_locked_hint') }}</p>
                        @else
                            <p class="mt-1 text-xs text-muted">{{ __('customers.tax_id_hint') }}</p>
                        @endif
                        @error('tax_id') <p class="form-error">{{ $message }}</p> @enderror
                        @if ($match && ! $match->is_customer && ! $match->trashed())
                            <p class="mt-1 text-sm" role="alert" data-tax-supplier>
                                {{ __('organizations.tax_id_supplier', ['name' => $match->name]) }}
                                @can('create', \App\Models\Customer::class)
                                    <button type="button" wire:click="addCustomerRole" class="btn btn-secondary btn-sm mt-1" data-action="add-customer-role">{{ __('organizations.add_customer_role') }}</button>
                                @endcan
                            </p>
                        @elseif ($match)
                            <p class="mt-1 text-sm" role="alert">
                                @can('view', $match)
                                    <a href="{{ route('customers.show', $match) }}" wire:navigate class="link">{{ __('customers.open_existing', ['name' => $match->name]) }}</a>
                                @endcan
                                @if ($match->trashed())
                                    <span class="block text-xs text-muted">{{ auth()->user()->can('restore', $match) ? __('customers.restore_hint_admin') : __('customers.restore_hint_other') }}</span>
                                @endif
                            </p>
                        @endif
                    </div>
                    <div>
                        <label for="industry_id" class="form-label">{{ __('customers.fields.industry') }}</label>
                        <select wire:model="industry_id" id="industry_id" class="form-input">
                            <option value="">—</option>
                            @foreach (\App\Models\ReferenceItem::options('industry', $industry_id !== '' ? (int) $industry_id : null) as $industryOption)
                                <option value="{{ $industryOption->id }}">{{ $industryOption->name() }}</option>
                            @endforeach
                        </select>
                        @error('industry_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="website" class="form-label">{{ __('customers.fields.website') }}</label>
                        <input wire:model="website" id="website" type="text" class="form-input" maxlength="255">
                        @error('website') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </fieldset>
            </section>
