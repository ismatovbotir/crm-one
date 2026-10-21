            <section class="card" aria-labelledby="address-heading">
                <h2 id="address-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('customers.sections.address') }}</h2>
                <fieldset @disabled(! $general) class="grid gap-5 p-6 sm:grid-cols-2">
                    <div>
                        <label for="country" class="form-label">{{ __('customers.fields.country') }}</label>
                        <div class="flex items-center gap-3">
                            <select wire:model.live="country" id="country" class="form-input" data-field="country">
                                @foreach ($this->countries as $code => $countryName)
                                    <option value="{{ $code }}" wire:key="c-{{ $code }}">{{ $countryName }}</option>
                                @endforeach
                            </select>
                            <span class="shrink-0 rounded border border-line bg-surface px-2 py-1 font-mono text-sm text-muted" data-iso-code title="ISO 3166-1 alpha-2">{{ $country }}</span>
                        </div>
                        @error('country') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="city" class="form-label">{{ __('customers.fields.city') }}</label>
                        <input wire:model="city" id="city" type="text" class="form-input" maxlength="120">
                        @error('city') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="address" class="form-label">{{ __('customers.fields.address') }}</label>
                        <textarea wire:model="address" id="address" rows="2" class="form-input"></textarea>
                        @error('address') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="postal_code" class="form-label">{{ __('customers.fields.postal_code') }}</label>
                        <input wire:model="postal_code" id="postal_code" type="text" class="form-input" maxlength="20">
                        @error('postal_code') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="phone" class="form-label">{{ __('customers.fields.phone') }}</label>
                        <input wire:model.live.debounce.400ms="phone" id="phone" type="tel" class="form-input" maxlength="32">
                        @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="email" class="form-label">{{ __('customers.fields.email') }}</label>
                        <input wire:model.live.debounce.400ms="email" id="email" type="email" class="form-input" maxlength="255">
                        @error('email') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </fieldset>
            </section>
