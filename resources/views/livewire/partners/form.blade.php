<div class="space-y-6" data-partner-form>
    <a href="{{ route('partners.index') }}" wire:navigate class="link text-sm">&larr; {{ __('partners.back') }}</a>

    @if ($errors->any())
        <div class="rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="alert">
            <p class="flex items-center gap-2 font-semibold"><x-icon name="warning" class="size-4" /> {{ __('customers.fix_errors') }}</p>
        </div>
    @endif

    @php
        $type = $this->legalType();
        $uz = $this->isUz();
        $isCustomer = in_array('customer', $roles, true);
        $isSupplier = array_intersect(['supplier', 'carrier'], $roles) !== [];
    @endphp

    @php
        $stepLabels = collect(\App\Livewire\Partners\Form::STEPS)->mapWithKeys(fn (string $key): array => [$key => __('partners.wizard.steps.'.$key)])->all();
        $lastStep = count($stepLabels);
    @endphp

    <div class="space-y-2">
        <x-wizard-steps :steps="$stepLabels" :current="$step" :furthest="$furthest" :label="__('partners.wizard.label')" />
        <p class="text-xs text-muted" aria-live="polite" data-step-of>{{ __('partners.wizard.step_of', ['current' => $step, 'total' => $lastStep]) }}</p>
    </div>

    @if ($this->duplicates->isNotEmpty())
        <div class="card p-4" role="status" aria-live="polite" data-duplicates>
            <p class="mb-3 flex items-center gap-2 text-sm font-medium text-brand-700"><x-icon name="warning" class="size-4" /> {{ __('customers.duplicates.found') }}</p>
            <ul class="space-y-3">
                @foreach ($this->duplicates as $duplicate)
                    <li wire:key="dup-{{ $duplicate['customer']->id }}" class="text-sm">
                        <a href="{{ route('customers.show', $duplicate['customer']) }}" wire:navigate class="link">{{ $duplicate['customer']->name }}</a>
                        @if ($duplicate['customer']->trashed())
                            <x-badge tone="outline">{{ __('common.archived') }}</x-badge>
                        @endif
                        <span class="block text-xs text-muted">
                            {{ collect($duplicate['reasons'])->map(fn ($reason) => __('customers.duplicates.reasons.'.$reason))->join(', ') }}
                            · {{ $duplicate['customer']->owner?->name }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="submit" class="space-y-6" novalidate>
        <div @if ($step !== 1) hidden @endif data-step-panel="roles">
        <fieldset class="card p-6" aria-describedby="roles-hint">
            <legend class="sr-only">{{ __('partners.fields.roles') }}</legend>
            <h2 class="text-base font-semibold text-ink">{{ __('partners.fields.roles') }} <x-required-mark /></h2>
            <p id="roles-hint" class="mt-1 text-sm text-muted">{{ __('partners.roles_hint') }}</p>
            <div class="mt-3 flex flex-wrap gap-6">
                @foreach (['customer', 'supplier', 'carrier'] as $roleOption)
                    @if (in_array($roleOption, $this->allowedRoles, true))
                        <label class="flex items-center gap-2 text-sm text-ink" wire:key="role-{{ $roleOption }}">
                            <input type="checkbox" wire:model.live="roles" value="{{ $roleOption }}" class="rounded border-line" data-role-option="{{ $roleOption }}">
                            {{ __('partners.roles.'.$roleOption) }}
                        </label>
                    @endif
                @endforeach
            </div>
            @error('roles') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        </fieldset>
        </div>

        <div @if ($step !== 2) hidden @endif data-step-panel="identity">
        <section class="card" aria-labelledby="identity-heading">
            <h2 id="identity-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('partners.sections.identity') }}</h2>
            <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label for="p-legal-type" class="form-label">{{ __('customers.fields.legal_type') }} <x-required-mark /></label>
                    <select wire:model.live="form.legal_type" id="p-legal-type" class="form-input" data-field="legal_type">
                        @foreach ($legalTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="p-name" class="form-label">{{ __('partners.fields.short_name') }} <x-required-mark /></label>
                    <input wire:model="form.name" id="p-name" type="text" maxlength="255" class="form-input" autocomplete="organization" aria-describedby="p-name-hint">
                    <p id="p-name-hint" class="mt-1 text-xs text-muted">{{ __('partners.hints.short_name') }}</p>
                    @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                @if ($type->requiresLegalName())
                    <div data-field="legal_name">
                        <label for="p-legal-name" class="form-label">{{ __('customers.fields.legal_name') }} <x-required-mark /></label>
                        <input wire:model="form.legal_name" id="p-legal-name" type="text" maxlength="255" class="form-input" aria-describedby="p-legal-hint">
                        <p id="p-legal-hint" class="mt-1 text-xs text-muted">{{ __('partners.hints.legal_name') }}</p>
                        @error('form.legal_name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="p-tax" class="form-label">{{ __('customers.fields.tax_id') }} @if ($type->requiresTaxId()) <x-required-mark /> @endif</label>
                    <input wire:model.live.debounce.400ms="form.tax_id" id="p-tax" type="text" inputmode="numeric" maxlength="32" class="form-input font-mono" aria-describedby="p-tax-hint">
                    <p id="p-tax-hint" class="mt-1 text-xs text-muted">{{ $uz ? ($type === \App\Enums\LegalType::Individual ? __('partners.hints.tax_uz_individual') : __('partners.hints.tax_uz')) : __('partners.hints.tax_foreign') }}</p>
                    @error('form.tax_id') <p class="form-error">{{ $message }}</p> @enderror
                    @if ($this->taxIdMatch)
                        <div class="mt-2 rounded-md border border-line p-3 text-sm" role="alert" data-tax-match>
                            <p class="text-ink">{{ __('partners.tax_id_exists', ['name' => $this->taxIdMatch->name, 'roles' => collect($this->taxIdMatch->roles())->map(fn ($r) => __('partners.roles.'.$r))->join(', ') ?: '—']) }}</p>
                            @if ($this->taxIdMatch->trashed())
                                <span class="mt-1 block text-xs text-muted">{{ auth()->user()->can('restore', $this->taxIdMatch) ? __('customers.restore_hint_admin') : __('customers.restore_hint_other') }}</span>
                            @endif
                            @if (! $this->taxIdMatch->trashed() && auth()->user()->can('editRoles', $this->taxIdMatch))
                                <button type="button" wire:click="addRolesToExisting" class="btn btn-secondary btn-sm mt-2" data-action="add-roles-existing">{{ __('partners.add_roles_existing') }}</button>
                            @endif
                        </div>
                    @endif
                </div>
                <div>
                    <label for="p-industry" class="form-label">{{ __('customers.fields.industry') }}</label>
                    <select wire:model="form.industry_id" id="p-industry" class="form-input">
                        <option value="">—</option>
                        @foreach ($industries as $industry)<option value="{{ $industry->id }}">{{ $industry->name() }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="p-website" class="form-label">{{ __('customers.fields.website') }}</label>
                    <input wire:model="form.website" id="p-website" type="url" inputmode="url" maxlength="255" class="form-input" placeholder="https://">
                    @error('form.website') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        </div>

        <div @if ($step !== 3) hidden @endif data-step-panel="address">
        <section class="card" aria-labelledby="address-heading">
            <h2 id="address-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('customers.sections.address') }}</h2>
            <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label for="p-country-search" class="form-label">{{ __('customers.fields.country') }} <x-required-mark /></label>
                    <input wire:model.live.debounce.300ms="countrySearch" id="p-country-search" type="search" class="form-input mb-2" placeholder="{{ __('partners.hints.search_country') }}" aria-label="{{ __('partners.hints.search_country') }}">
                    <select wire:model.live="form.country" id="p-country" class="form-input" aria-label="{{ __('customers.fields.country') }}" data-field="country">
                        @foreach ($this->countries as $code => $countryName)
                            <option value="{{ $code }}" wire:key="c-{{ $code }}">{{ $countryName }}</option>
                        @endforeach
                    </select>
                    @error('form.country') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                @if ($uz)
                    <div data-field="region">
                        <label for="p-region" class="form-label">{{ __('partners.fields.region') }}</label>
                        <select wire:model.live="form.region_id" id="p-region" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->regions as $region)<option value="{{ $region->id }}">{{ $region->name() }}</option>@endforeach
                        </select>
                    </div>
                    <div data-field="city_id">
                        <label for="p-city-id" class="form-label">{{ __('customers.fields.city') }}</label>
                        <select wire:model="form.city_id" id="p-city-id" class="form-input" @disabled($form['region_id'] === '')>
                            <option value="">—</option>
                            @foreach ($this->cities as $city)<option value="{{ $city->id }}">{{ $city->name() }}</option>@endforeach
                        </select>
                        @error('form.city_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div data-field="city_text">
                        <label for="p-city" class="form-label">{{ __('customers.fields.city') }}</label>
                        <input wire:model="form.city" id="p-city" type="text" maxlength="120" class="form-input" autocomplete="address-level2">
                    </div>
                @endif
                <div class="md:col-span-2">
                    <label for="p-address" class="form-label">{{ __('customers.fields.address') }}</label>
                    <textarea wire:model="form.address" id="p-address" rows="2" class="form-input" autocomplete="street-address"></textarea>
                </div>
                <div>
                    <label for="p-postal" class="form-label">{{ __('customers.fields.postal_code') }}</label>
                    <input wire:model="form.postal_code" id="p-postal" type="text" @if ($uz) inputmode="numeric" maxlength="6" @else maxlength="20" @endif class="form-input" autocomplete="postal-code">
                    @error('form.postal_code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-phone" class="form-label">{{ __('customers.fields.phone') }}</label>
                    <input wire:model="form.phone" id="p-phone" type="tel" maxlength="32" class="form-input" autocomplete="tel">
                </div>
                <div>
                    <label for="p-email" class="form-label">{{ __('customers.fields.email') }}</label>
                    <input wire:model="form.email" id="p-email" type="email" maxlength="255" class="form-input" autocomplete="email">
                    @error('form.email') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>
        </div>

        <div @if ($step !== 4) hidden @endif class="space-y-6" data-step-panel="terms">
        <section class="card" aria-labelledby="terms-heading">
            <h2 id="terms-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('partners.sections.terms') }}</h2>
            <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                <div>
                    <label for="p-locale" class="form-label">{{ __('customers.fields.preferred_locale') }} <x-required-mark /></label>
                    <select wire:model="form.preferred_locale" id="p-locale" class="form-input">
                        @foreach ($locales as $locale)<option value="{{ $locale->value }}">{{ $locale->label() }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label for="p-currency" class="form-label">{{ __('customers.fields.currency') }} <x-required-mark /></label>
                    <select wire:model="form.currency" id="p-currency" class="form-input">
                        @foreach ($this->currencies as $code)<option value="{{ $code }}">{{ $code }}</option>@endforeach
                    </select>
                    @error('form.currency') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="p-owner-search" class="form-label">{{ __('customers.fields.owner_id') }} <x-required-mark /></label>
                    @if ($this->canChangeOwner())
                        <input wire:model.live.debounce.300ms="ownerSearch" id="p-owner-search" type="search" class="form-input mb-2" placeholder="{{ __('partners.hints.search_owner') }}" aria-label="{{ __('partners.hints.search_owner') }}">
                    @endif
                    <select wire:model="form.owner_id" id="p-owner" class="form-input" aria-label="{{ __('customers.fields.owner_id') }}" @disabled(! $this->canChangeOwner())>
                        @foreach ($this->owners as $ownerOption)
                            <option value="{{ $ownerOption->id }}" wire:key="po-{{ $ownerOption->id }}">{{ $ownerOption->name }}</option>
                        @endforeach
                    </select>
                    @unless ($this->canChangeOwner())
                        <p class="mt-1 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('customers.owner_locked') }}</p>
                    @endunless
                    @error('form.owner_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="md:col-span-2 xl:col-span-3">
                    <label for="p-notes" class="form-label">{{ __('customers.fields.notes') }}</label>
                    <textarea wire:model="form.notes" id="p-notes" rows="2" class="form-input"></textarea>
                </div>
            </div>
        </section>

        @if ($isCustomer)
            <section class="card" aria-labelledby="customer-heading" data-section="customer">
                <h2 id="customer-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('partners.sections.customer') }}</h2>
                <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label for="p-sale-type" class="form-label">{{ __('customers.fields.sale_type') }} <x-required-mark /></label>
                        <select wire:model="form.sale_type" id="p-sale-type" class="form-input" aria-describedby="p-sale-hint" data-field="sale_type">
                            @foreach (\App\Enums\SaleType::cases() as $saleType)<option value="{{ $saleType->value }}">{{ $saleType->label() }}</option>@endforeach
                        </select>
                        <p id="p-sale-hint" class="mt-1 text-xs text-muted">{{ __('partners.hints.sale_type') }}</p>
                    </div>
                    <div>
                        <label for="p-terms" class="form-label">{{ __('customers.fields.payment_terms_days') }}</label>
                        <input wire:model="form.payment_terms_days" id="p-terms" type="number" min="0" max="365" class="form-input" aria-describedby="p-terms-hint" @disabled(! $canTerms)>
                        <p id="p-terms-hint" class="mt-1 text-xs text-muted">{{ __('partners.hints.payment_terms') }}</p>
                        @error('form.payment_terms_days') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div data-sync-with-1c>
                        <label class="flex items-start gap-2 text-sm text-ink">
                            <input wire:model="syncWith1c" type="checkbox" class="mt-0.5 rounded border-line" aria-describedby="p-sync-hint">
                            <span>{{ __('customers.fields.sync_with_1c') }}<span id="p-sync-hint" class="block text-xs text-muted">{{ __('customers.sync_with_1c_hint') }}</span></span>
                        </label>
                        @error('syncWith1c') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>
        @endif

        @if ($isSupplier)
            <section class="card" aria-labelledby="supplier-heading" data-section="supplier">
                <h2 id="supplier-heading" class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('partners.sections.supplier') }}</h2>
                <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                    <p class="text-sm text-muted md:col-span-2 xl:col-span-3">{{ __('partners.supplier_hint') }}</p>
                    <div>
                        <label for="p-category" class="form-label">{{ __('partners.fields.supplier_category') }}</label>
                        <select wire:model="form.supplier_category_id" id="p-category" class="form-input">
                            <option value="">—</option>
                            @foreach ($supplierCategories as $category)<option value="{{ $category->id }}">{{ $category->name() }}</option>@endforeach
                        </select>
                    </div>
                    @if (in_array('carrier', $roles, true))
                        <div>
                            <label for="p-fleet" class="form-label">{{ __('partners.fields.fleet_size') }}</label>
                            <input wire:model="form.fleet_size" id="p-fleet" type="number" min="0" class="form-input">
                            @error('form.fleet_size') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <fieldset class="flex items-end gap-6">
                            <legend class="sr-only">{{ __('partners.fields.permits') }}</legend>
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="hasTir" type="checkbox" class="rounded border-line"> TIR</label>
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="hasAdr" type="checkbox" class="rounded border-line"> ADR</label>
                        </fieldset>
                    @endif
                </div>
            </section>
        @endif
        </div>

        <div @if ($step !== 5) hidden @endif data-step-panel="contact">
        <section class="card" aria-labelledby="pc-heading">
            <div class="border-b border-line px-6 py-4">
                <h2 id="pc-heading" class="text-base font-semibold text-ink">{{ __('customers.sections.first_contact') }}</h2>
                <p class="mt-1 text-xs text-muted">{{ __('customers.first_contact_hint') }} {{ __('partners.wizard.optional') }}</p>
            </div>
            <div class="grid gap-5 p-6 md:grid-cols-2 xl:grid-cols-3">
                @foreach (['first_name' => 'text', 'last_name' => 'text'] as $field => $inputType)
                    <div wire:key="pcf-{{ $field }}">
                        <label for="pc-{{ $field }}" class="form-label">{{ __('customers.contacts.fields.'.$field) }}</label>
                        <input wire:model="form.contact_{{ $field }}" id="pc-{{ $field }}" type="{{ $inputType }}" class="form-input">
                        @error('form.contact_'.$field) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <div>
                    <label for="pc-position" class="form-label">{{ __('customers.contacts.fields.position') }}</label>
                    <input wire:model="form.contact_position" id="pc-position" type="text" list="pc-positions" maxlength="120" class="form-input" aria-describedby="pc-position-hint" data-field="contact_position">
                    <datalist id="pc-positions">
                        @foreach ($this->positions as $position)<option value="{{ $position->name() }}"></option>@endforeach
                    </datalist>
                    <p id="pc-position-hint" class="mt-1 text-xs text-muted">{{ __('partners.hints.position') }}</p>
                </div>
                <div>
                    <label for="pc-email" class="form-label">{{ __('customers.contacts.fields.email') }}</label>
                    <input wire:model="form.contact_email" id="pc-email" type="email" class="form-input">
                    @error('form.contact_email') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pc-phone" class="form-label">{{ __('customers.contacts.fields.phone') }}</label>
                    <input wire:model="form.contact_phone" id="pc-phone" type="tel" class="form-input">
                </div>
            </div>
        </section>
        </div>

        <div class="flex justify-end gap-3">
            <a href="{{ route('partners.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
            @if ($step > 1)
                <button type="button" wire:click="back" class="btn btn-secondary" data-action="wizard-back">&larr; {{ __('partners.wizard.back') }}</button>
            @endif
            @if ($step < $lastStep)
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="submit" data-action="wizard-next">{{ __('partners.wizard.next') }} &rarr;</button>
            @else
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="submit" data-action="create-partner">{{ __('partners.create') }}</button>
            @endif
        </div>
    </form>
</div>
