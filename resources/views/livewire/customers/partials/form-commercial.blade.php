            <section class="card" aria-labelledby="commercial-heading">
                <h2 id="commercial-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('customers.sections.commercial') }}</h2>
                <div class="grid gap-5 p-6 sm:grid-cols-2">
                    <div>
                        <label for="currency" class="form-label">{{ __('customers.fields.currency') }}</label>
                        <select wire:model.live="currency" id="currency" class="form-input" @disabled(! $general)>
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        @error('currency') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="preferred_locale" class="form-label">{{ __('customers.fields.preferred_locale') }}</label>
                        <select wire:model="preferred_locale" id="preferred_locale" class="form-input" @disabled(! $general)>
                            <option value="">{{ __('customers.no_preference') }}</option>
                            @foreach ($locales as $locale)
                                <option value="{{ $locale->value }}">{{ $locale->label() }}</option>
                            @endforeach
                        </select>
                        @error('preferred_locale') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="sale_type" class="form-label">{{ __('customers.fields.sale_type') }}</label>
                        <select wire:model="sale_type" id="sale_type" class="form-input" @disabled(! $general)>
                            @foreach (\App\Enums\SaleType::cases() as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-muted">{{ __('customers.sale_type_hint') }}</p>
                        @error('sale_type') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="payment_terms_days" class="form-label">{{ __('customers.fields.payment_terms_days') }}</label>
                        <input wire:model="payment_terms_days" id="payment_terms_days" type="number" min="0" max="365" class="form-input" @disabled(! $this->canEditCommercialTerms())>
                        @unless ($this->canEditCommercialTerms())
                            <p class="mt-1 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('customers.commercial_locked') }}</p>
                        @endunless
                        @error('payment_terms_days') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2" data-sync-with-1c>
                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input wire:model="sync_with_1c" type="checkbox" class="mt-0.5 rounded border-line" @disabled(! $general) aria-describedby="sync-hint">
                            <span>{{ __('customers.fields.sync_with_1c') }}<span id="sync-hint" class="block text-xs text-muted">{{ __('customers.sync_with_1c_hint') }}</span></span>
                        </label>
                        @error('sync_with_1c') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="owner_id" class="form-label">{{ __('customers.fields.owner_id') }}</label>
                        <select wire:model="owner_id" id="owner_id" class="form-input" @disabled(! $this->canChangeOwner())>
                            @foreach ($this->owners as $ownerOption)
                                <option value="{{ $ownerOption->id }}" wire:key="o-{{ $ownerOption->id }}">{{ $ownerOption->name }}</option>
                            @endforeach
                        </select>
                        @unless ($this->canChangeOwner())
                            <p class="mt-1 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('customers.owner_locked') }}</p>
                        @endunless
                        @error('owner_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="notes" class="form-label">{{ __('customers.fields.notes') }}</label>
                        <textarea wire:model="notes" id="notes" rows="3" class="form-input" @disabled(! $general)></textarea>
                        @error('notes') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>
