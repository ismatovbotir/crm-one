<div class="space-y-6" x-data="leadForm" @keydown.alt.s.window.prevent="shortcutSave" @keydown.alt.n.window.prevent="shortcutSaveAnother" data-lead-form>
    @php
        $summary = $this->summary;
        $context = $this->customerContext;
        $isNew = $leadId === null;
        $wizard = ! $singlePage;
        $show = fn (int $section): bool => ! $wizard || $step === $section;
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ $leadId ? route('leads.show', $leadId) : route('leads.index') }}" wire:navigate class="link text-sm">&larr; {{ __('leads.back') }}</a>
        <label class="flex items-center gap-2 text-sm text-ink" data-single-page-switch>
            <input type="checkbox" wire:click="toggleSinglePage" @checked($singlePage) class="size-4 accent-brand-600">
            {{ __('leads.wizard.single_page') }}
        </label>
    </div>

    @if ($wizard)
        <nav aria-label="{{ __('leads.wizard.progress') }}" data-lead-steps>
            <ol class="flex flex-wrap items-center gap-2 text-sm">
                @foreach ($this->stepStates as $number => [$key, $state])
                    <li class="flex items-center gap-2" data-step="{{ $key }}" data-state="{{ $state }}">
                        <button type="button" wire:click="goToStep({{ $number }})" @disabled($number > $reachedStep) @if ($state === 'current') aria-current="step" @endif
                                @class(['flex items-center gap-2 rounded-md px-2 py-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600', 'cursor-default' => $number > $reachedStep])>
                            <span @class([
                                'inline-flex size-6 items-center justify-center rounded-full border text-xs font-semibold',
                                'border-brand-700 bg-brand-700 text-white' => $state === 'current',
                                'border-ink bg-ink text-white' => $state === 'done',
                                'border-line text-muted' => $state === 'todo',
                            ]) aria-hidden="true">{{ $number }}</span>
                            <span @class(['font-semibold text-ink' => $state === 'current', 'text-ink' => $state === 'done', 'text-muted' => $state === 'todo'])>{{ __('leads.wizard.steps.'.$key) }}</span>
                            <span class="sr-only">({{ __('leads.wizard.states.'.$state) }})</span>
                        </button>
                        @if (! $loop->last)<span class="text-muted" aria-hidden="true">&rarr;</span>@endif
                    </li>
                @endforeach
            </ol>
        </nav>
    @endif

    @if ($isNew && $draftSavedAt)
        <div class="card flex flex-wrap items-center justify-between gap-3 border-brand-600 p-4" role="status" data-draft-banner>
            <p class="text-sm text-ink">{{ __('leads.draft.available', ['at' => \Carbon\CarbonImmutable::parse($draftSavedAt)->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}</p>
            <div class="flex gap-2">
                <button type="button" wire:click="restoreDraft" class="btn btn-primary btn-sm" data-action="restore-draft">{{ __('leads.draft.restore') }}</button>
                <button type="button" wire:click="discardDraft" class="btn btn-secondary btn-sm" data-action="discard-draft">{{ __('leads.draft.discard') }}</button>
            </div>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="alert" tabindex="-1" data-error-summary>
            <p class="flex items-center gap-2 font-semibold"><x-icon name="warning" class="size-4" /> {{ __('leads.fix_errors', ['count' => $errors->count()]) }}</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form wire:submit="{{ $wizard && $step < 4 ? 'nextStep' : "save('stay')" }}" class="grid gap-6 lg:grid-cols-[1fr_22rem]" novalidate @input="markDirty" @change="markDirty" @submit="markClean" @if ($isNew) wire:poll.20s.visible="autosave" @endif>
        <div class="min-w-0 space-y-6">
            {{-- 1. Company and contact (wizard step 1) --}}
            @if ($show(1))
            <section class="card" aria-labelledby="lf-company">
                <h2 id="lf-company" tabindex="-1" @if ($wizard) data-step-heading @endif class="flex items-center gap-2 border-b border-line px-6 py-4 text-base font-semibold text-ink"><span class="inline-flex size-6 items-center justify-center rounded-full bg-ink text-xs text-white" aria-hidden="true">1</span> {{ __('leads.sections.company') }}</h2>
                <div class="space-y-5 p-6">
                    @if ($this->customer)
                        <div class="flex flex-wrap items-start justify-between gap-3 rounded-md border border-line bg-neutral-50 px-4 py-3" data-selected-customer>
                            <div>
                                <p class="font-semibold text-ink">{{ $this->customer->name }}</p>
                                <p class="text-xs text-muted">{{ collect([$this->customer->tax_id, $this->customer->city, $this->customer->sale_type->label()])->filter()->join(' · ') }}</p>
                            </div>
                            <button type="button" wire:click="clearCustomer" class="btn btn-secondary btn-sm">{{ __('leads.change_customer') }}</button>
                        </div>

                        @if ($context)
                            <div class="grid gap-3 text-sm sm:grid-cols-3" data-customer-context>
                                <div class="rounded-md border border-line p-3">
                                    <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('leads.context.open_offers') }}</p>
                                    @forelse ($context['offers'] as $offer)
                                        <p class="mt-1"><a href="{{ route('offers.show', $offer) }}" wire:navigate class="link font-mono text-xs">{{ $offer->displayNumber() }}</a> <span class="text-xs text-muted">{{ $offer->status->label() }}</span></p>
                                    @empty
                                        <p class="mt-1 text-muted">—</p>
                                    @endforelse
                                </div>
                                <div class="rounded-md border border-line p-3">
                                    <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('leads.context.last_orders') }}</p>
                                    @forelse ($context['orders'] as $order)
                                        <p class="mt-1 font-mono text-xs text-ink">{{ $order->number }} <span class="font-sans text-muted">{{ $order->status->label() }}</span></p>
                                    @empty
                                        <p class="mt-1 text-muted">—</p>
                                    @endforelse
                                </div>
                                <div class="rounded-md border border-line p-3">
                                    <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('leads.context.receivable') }}</p>
                                    <p class="mt-1 font-semibold text-ink tabular-nums">{{ \App\Support\Money::of($context['outstanding_minor'], $context['currency'])->format() }}</p>
                                    @if ($context['overdue'] > 0)
                                        <p class="mt-1 flex items-center gap-1 text-xs text-brand-800"><x-icon name="warning" class="size-3.5" /> {{ __('leads.context.overdue', ['count' => $context['overdue']]) }}</p>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <div>
                            <label for="lead-contact" class="form-label">{{ __('leads.fields.contact') }}</label>
                            <select wire:model.live="contactId" id="lead-contact" class="form-input">
                                <option value="">{{ __('leads.no_contact') }}</option>
                                @foreach ($this->contacts as $contact)
                                    <option value="{{ $contact->id }}" wire:key="contact-{{ $contact->id }}">{{ $contact->fullName() }}{{ $contact->email ? ' · '.$contact->email : '' }}{{ $contact->phone ? ' · '.$contact->phone : '' }}</option>
                                @endforeach
                            </select>
                            @error('contactId') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @else
                        <div>
                            <label for="unified-search" class="form-label">{{ __('leads.unified_search') }}</label>
                            <input @keydown.enter.prevent wire:model.live.debounce.300ms="search" id="unified-search" type="search" placeholder="{{ __('leads.unified_search_placeholder') }}" class="form-input" autocomplete="off" aria-describedby="unified-search-hint">
                            <p id="unified-search-hint" class="mt-1 text-xs text-muted">{{ __('leads.new_company_hint') }}</p>
                            @php($results = $this->searchResults)
                            @if ($results['customers']->isNotEmpty() || $results['leads']->isNotEmpty())
                                <div class="mt-2 divide-y divide-line rounded-md border border-line" data-search-results>
                                    @foreach ($results['customers'] as $option)
                                        <button type="button" wire:click="selectCustomer({{ $option->id }})" wire:key="sr-c-{{ $option->id }}" class="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-brand-50/40">
                                            <span><span class="font-medium text-ink">{{ $option->name }}</span> <span class="text-xs text-muted">{{ __('leads.search_kinds.customer') }}</span>@if ($option->origin?->value === 'onec') <x-badge tone="outline" class="ml-1">1C</x-badge>@endif</span>
                                            <span class="font-mono text-xs text-muted">{{ $option->tax_id }}</span>
                                        </button>
                                    @endforeach
                                    @foreach ($results['leads'] as $other)
                                        <a href="{{ route('leads.show', $other) }}" wire:navigate wire:key="sr-l-{{ $other->id }}" class="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm hover:bg-brand-50/40">
                                            <span><span class="font-medium text-ink">{{ $other->companyLabel() }}</span> <span class="text-xs text-muted">{{ __('leads.search_kinds.lead') }} · {{ $other->status->label() }}</span></span>
                                            <span class="font-mono text-xs text-muted">{{ $other->number }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                            @error('customerId') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label for="company-name" class="form-label">{{ __('leads.fields.company_name') }} <span class="text-brand-700" aria-hidden="true">*</span></label>
                                <input wire:model.live.debounce.500ms="company_name" id="company-name" type="text" class="form-input" maxlength="255" aria-required="true">
                                @error('company_name') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="tax-id" class="form-label">{{ __('leads.fields.tax_id') }}</label>
                                <input wire:model.live.debounce.500ms="tax_id" id="tax-id" type="text" inputmode="numeric" class="form-input font-mono" maxlength="20" aria-describedby="tax-id-hint">
                                @if ($this->taxIdError)
                                    <p id="tax-id-hint" class="form-error" data-tax-id-error>{{ __('leads.tax_id_errors.'.$this->taxIdError) }}</p>
                                @else
                                    <p id="tax-id-hint" class="mt-1 text-xs text-muted">{{ __('leads.tax_id_hint') }}</p>
                                @endif
                                @error('tax_id') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-3">
                                <label for="industry" class="form-label">{{ __('leads.fields.industry') }}</label>
                                <input wire:model="industry" id="industry" type="text" class="form-input" maxlength="120">
                            </div>
                        </div>
                    @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="contact-name" class="form-label">{{ __('leads.fields.contact_name') }}</label>
                            <input wire:model="contact_name" id="contact-name" type="text" class="form-input" maxlength="255" autocomplete="name">
                            @error('contact_name') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="contact-position" class="form-label">{{ __('leads.fields.contact_position') }}</label>
                            <input wire:model="contact_position" id="contact-position" type="text" class="form-input" maxlength="120">
                        </div>
                        <div>
                            <label for="contact-email" class="form-label">{{ __('leads.fields.contact_email') }}</label>
                            <input wire:model.live.debounce.500ms="contact_email" id="contact-email" type="email" class="form-input" maxlength="255" autocomplete="email">
                            @error('contact_email') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="contact-phone" class="form-label">{{ __('leads.fields.contact_phone') }}</label>
                            <input wire:model.live.debounce.500ms="contact_phone" id="contact-phone" type="tel" class="form-input" maxlength="32" autocomplete="tel" aria-describedby="phone-hint">
                            @if ($this->normalizedPhone && $this->normalizedPhone !== $contact_phone)
                                <p id="phone-hint" class="mt-1 text-xs text-muted" data-phone-preview>{{ __('leads.phone_saved_as', ['phone' => $this->normalizedPhone]) }}</p>
                            @else
                                <p id="phone-hint" class="mt-1 text-xs text-muted">{{ __('leads.phone_hint') }}</p>
                            @endif
                            @error('contact_phone') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="lead-locale" class="form-label">{{ __('leads.fields.locale') }}</label>
                            <select wire:model="locale" id="lead-locale" class="form-input">
                                <option value="">{{ __('leads.no_preference') }}</option>
                                @foreach ($locales as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="source" class="form-label">{{ __('leads.fields.source') }}</label>
                            <select wire:model="source" id="source" class="form-input">
                                @foreach ($sources as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('source') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="campaign" class="form-label">{{ __('leads.fields.campaign') }}</label>
                            <input wire:model="campaign" id="campaign" type="text" class="form-input" maxlength="120">
                        </div>
                        <div>
                            <label for="lead-sale-type" class="form-label">{{ __('leads.fields.sale_type') }}</label>
                            <select wire:model="sale_type" id="lead-sale-type" class="form-input">
                                <option value="">{{ __('leads.no_preference') }}</option>
                                @foreach ($saleTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </section>
            @endif

            {{-- 2. Opportunity and 3. products of interest (wizard step 2) --}}
            @if ($show(2))
            <section class="card" aria-labelledby="lf-opportunity">
                <h2 id="lf-opportunity" tabindex="-1" @if ($wizard) data-step-heading @endif class="flex items-center gap-2 border-b border-line px-6 py-4 text-base font-semibold text-ink"><span class="inline-flex size-6 items-center justify-center rounded-full bg-ink text-xs text-white" aria-hidden="true">2</span> {{ __('leads.sections.need') }}</h2>
                <div class="grid gap-4 p-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <label for="priority" class="form-label">{{ __('leads.fields.priority') }}</label>
                        <select wire:model.live="priority" id="priority" class="form-input">
                            @foreach ($priorities as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="est-value" class="form-label">{{ __('leads.fields.est_value') }}</label>
                        <div class="flex gap-2">
                            <input wire:model.blur="est_value" id="est-value" type="text" inputmode="decimal" class="form-input text-right tabular-nums" maxlength="30">
                            <select wire:model="currency" class="form-input w-24" aria-label="{{ __('leads.fields.currency') }}">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('est_value') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="expected-close" class="form-label">{{ __('leads.fields.expected_close_date') }}</label>
                        <input wire:model.blur="expected_close_date" id="expected-close" type="date" class="form-input">
                        @error('expected_close_date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label for="need" class="form-label">{{ __('leads.fields.need') }}</label>
                        <textarea wire:model.blur="need" id="need" rows="3" class="form-input" maxlength="5000"></textarea>
                        @error('need') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- 3. Products of interest --}}
            <section class="card" aria-labelledby="lf-items">
                <h2 id="lf-items" class="flex items-center gap-2 border-b border-line px-6 py-4 text-base font-semibold text-ink"><span class="inline-flex size-6 items-center justify-center rounded-full bg-ink text-xs text-white" aria-hidden="true">3</span> {{ __('leads.fields.items') }}</h2>
                <div class="space-y-3 p-6">
                    <label for="item-search" class="sr-only">{{ __('leads.item_search') }}</label>
                    <input @keydown.enter.prevent wire:model.live.debounce.300ms="itemSearch" id="item-search" type="search" placeholder="{{ __('leads.item_search') }}" class="form-input" autocomplete="off">
                    @if ($this->itemResults->isNotEmpty())
                        <ul class="max-h-64 divide-y divide-line overflow-y-auto rounded-md border border-line" aria-label="{{ __('leads.fields.items') }}">
                            @foreach ($this->itemResults as $result)
                                <li wire:key="item-r-{{ $result->id }}">
                                    <button type="button" wire:click="addItem({{ $result->id }})" class="flex w-full flex-wrap items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-brand-50/40">
                                        <span class="text-ink">{{ $result->name }} <span class="font-mono text-xs text-muted">{{ $result->sku }}</span> @if ($result->is_serialized) <x-badge tone="outline">{{ __('orders.lines.serialized') }}</x-badge> @endif</span>
                                        <span class="text-xs text-muted tabular-nums">{{ $result->salePrice()?->format() ?? '—' }} · {{ __('leads.in_stock', ['qty' => \App\Support\Quantity::trimmed($result->availableStock()), 'unit' => $result->unit?->code]) }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($items !== [])
                        <table class="min-w-full divide-y divide-line text-sm" data-lead-items>
                            <thead class="text-left text-xs text-muted uppercase">
                                <tr><th scope="col" class="py-2">{{ __('leads.fields.item') }}</th><th scope="col" class="w-36 py-2">{{ __('leads.fields.quantity') }}</th><th scope="col" class="w-10 py-2"><span class="sr-only">{{ __('common.actions') }}</span></th></tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($items as $index => $row)
                                    <tr wire:key="lead-item-{{ $row['item_id'] }}">
                                        <td class="py-2 pr-3 text-ink">{{ $row['name'] }} <span class="font-mono text-xs text-muted">{{ $row['sku'] }}</span></td>
                                        <td class="py-2 pr-3">
                                            <div class="flex items-center gap-1">
                                                <input wire:model.blur="items.{{ $index }}.quantity" type="text" inputmode="decimal" class="form-input py-1 text-right tabular-nums" aria-label="{{ __('leads.fields.quantity') }}: {{ $row['name'] }}">
                                                <span class="text-xs text-muted">{{ $row['unit'] }}</span>
                                            </div>
                                            @error('items.'.$index.'.quantity') <p class="form-error">{{ $message }}</p> @enderror
                                        </td>
                                        <td class="py-2 text-right"><button type="button" wire:click="removeItem({{ $index }})" class="text-muted hover:text-brand-700" aria-label="{{ __('common.remove') }}: {{ $row['name'] }}"><x-icon name="close" class="size-4" /></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <p class="text-sm text-muted">{{ __('leads.no_items') }}</p>
                    @endif
                </div>
            </section>
            @endif

            {{-- 4. Qualification and 5. assignment (wizard step 3) --}}
            @if ($show(3))
            <section class="card" aria-labelledby="lf-bant">
                <h2 id="lf-bant" tabindex="-1" @if ($wizard) data-step-heading @endif class="flex items-center gap-2 border-b border-line px-6 py-4 text-base font-semibold text-ink"><span class="inline-flex size-6 items-center justify-center rounded-full bg-ink text-xs text-white" aria-hidden="true">4</span> {{ __('leads.sections.bant') }}</h2>
                <div class="space-y-3 p-6">
                    <p class="text-sm text-muted">{{ __('leads.bant_hint') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach (\App\Models\Lead::BANT as $flag)
                            <label class="flex items-start gap-2 rounded-md border border-line p-3 text-sm text-ink" wire:key="bant-{{ $flag }}">
                                <input wire:model.live="{{ $flag }}" type="checkbox" class="mt-0.5 size-4 accent-brand-600">
                                <span><span class="font-medium">{{ __('leads.gate.'.$flag) }}</span><span class="block text-xs text-muted">{{ __('leads.bant.'.$flag) }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </section>

            {{-- 5. Assignment --}}
            <section class="card" aria-labelledby="lf-assign">
                <h2 id="lf-assign" class="flex items-center gap-2 border-b border-line px-6 py-4 text-base font-semibold text-ink"><span class="inline-flex size-6 items-center justify-center rounded-full bg-ink text-xs text-white" aria-hidden="true">5</span> {{ __('leads.sections.assignment') }}</h2>
                <div class="grid gap-4 p-6 sm:grid-cols-2">
                    <div>
                        <label for="owner" class="form-label">{{ __('leads.fields.owner') }}</label>
                        <select wire:model="owner_id" id="owner" class="form-input" @disabled(! $this->canAssign())>
                            @if (! $this->canAssign())
                                <option value="{{ $owner_id }}">{{ $leadId ? ($this->lead?->owner?->name ?? __('leads.unassigned')) : auth()->user()->name }}</option>
                            @endif
                            @foreach ($this->canAssign() ? $this->assignableOwners : [] as $candidate)
                                <option value="{{ $candidate->id }}" wire:key="owner-{{ $candidate->id }}">{{ $candidate->name }}</option>
                            @endforeach
                        </select>
                        @unless ($this->canAssign())
                            <p class="mt-1 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('leads.owner_locked') }}</p>
                        @endunless
                        @error('owner_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($isNew)
                        <fieldset class="space-y-3 sm:col-span-2">
                            <legend class="sr-only">{{ __('leads.follow_up.title') }}</legend>
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="followUp" type="checkbox" class="size-4 accent-brand-600"> {{ __('leads.follow_up.create') }}</label>
                            @if ($followUp)
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="follow-up-due" class="form-label">{{ __('leads.fields.follow_up_due') }}</label>
                                        <input wire:model="followUpDue" id="follow-up-due" type="datetime-local" class="form-input">
                                        <p class="mt-1 text-xs text-muted">{{ __('leads.follow_up.reminder_hint') }}</p>
                                        @error('followUpDue') <p class="form-error">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label for="follow-up-note" class="form-label">{{ __('leads.fields.follow_up_note') }}</label>
                                        <input wire:model="followUpNote" id="follow-up-note" type="text" class="form-input" maxlength="500">
                                    </div>
                                </div>
                            @endif
                        </fieldset>
                    @endif
                </div>
            </section>
            @endif

            {{-- Review (wizard step 4): what will be saved, with edit links per step. --}}
            @if ($wizard && $step === 4)
                <section class="card" aria-labelledby="lf-review" data-lead-review>
                    <h2 id="lf-review" tabindex="-1" data-step-heading class="border-b border-line px-6 py-4 text-base font-semibold text-ink">{{ __('leads.wizard.review_title') }}</h2>
                    <dl class="divide-y divide-line text-sm">
                        @foreach ($this->reviewRows as $number => $rows)
                            <div class="px-6 py-4" wire:key="review-{{ $number }}">
                                <div class="flex items-center justify-between gap-2">
                                    <h3 class="font-semibold text-ink">{{ __('leads.wizard.steps.'.\App\Livewire\Leads\Form::STEPS[$number]) }}</h3>
                                    <button type="button" wire:click="goToStep({{ $number }})" class="link text-xs" data-edit-step="{{ $number }}">{{ __('leads.wizard.edit') }}</button>
                                </div>
                                <div class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                    @foreach ($rows as $label => $value)
                                        <div class="flex gap-2"><dt class="text-muted">{{ $label }}:</dt><dd class="text-ink">{{ $value }}</dd></div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            @if ($wizard)
                <div class="flex flex-wrap items-center justify-between gap-2" data-step-nav>
                    @if ($step > 1)
                        <button type="button" wire:click="previousStep" class="btn btn-secondary" data-action="previous-step">&larr; {{ __('leads.wizard.back') }}</button>
                    @else
                        <span></span>
                    @endif
                    @if ($step < 4)
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="nextStep" data-action="next-step">{{ __('leads.wizard.next') }} &rarr;</button>
                    @endif
                </div>
            @endif
        </div>

        {{-- Sticky summary --}}
        <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start" aria-label="{{ __('leads.summary.title') }}" data-lead-summary>
            <section class="card space-y-4 p-5" aria-live="polite">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('leads.summary.score') }}</p>
                        <p class="text-3xl font-semibold text-ink tabular-nums" data-lead-score="{{ $summary['score'] }}">{{ $summary['score'] }}<span class="text-base text-muted">/100</span></p>
                    </div>
                    <x-badge :tone="$summary['band'] === 'high' ? 'dark' : ($summary['band'] === 'medium' ? 'silver' : 'outline')" data-score-band="{{ $summary['band'] }}">{{ __('leads.summary.bands.'.$summary['band']) }}</x-badge>
                </div>
                <div>
                    <div class="flex justify-between text-xs text-muted"><span>{{ __('leads.summary.completeness') }}</span><span class="tabular-nums">{{ $summary['completeness'] }}%</span></div>
                    <div class="mt-1 h-2 overflow-hidden rounded-full bg-neutral-200" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $summary['completeness'] }}" aria-label="{{ __('leads.summary.completeness') }}">
                        <div class="h-full bg-ink" style="width: {{ $summary['completeness'] }}%"></div>
                    </div>
                </div>
                <div>
                    <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('leads.summary.next') }}</p>
                    <p class="mt-1 text-sm text-ink" data-suggestion="{{ $summary['suggestion'] }}">{{ __('leads.suggestions.'.$summary['suggestion']) }}</p>
                    @if ($summary['gaps'] !== [])
                        <p class="mt-2 text-xs text-muted">{{ __('leads.summary.required_for_qualify') }}</p>
                        <ul class="mt-1 space-y-1 text-xs">
                            @foreach ($summary['gaps'] as $gap)
                                <li class="flex items-center gap-1 text-brand-800" wire:key="gap-{{ $gap }}"><x-icon name="close" class="size-3.5" /> {{ __('leads.gate.'.$gap) }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            @if ($this->duplicateCustomers->isNotEmpty() || $this->duplicateLeads->isNotEmpty())
                <section class="card space-y-3 border-brand-600 p-5" role="status" data-duplicates>
                    <p class="flex items-center gap-2 text-sm font-semibold text-brand-800"><x-icon name="warning" class="size-4" /> {{ __('leads.duplicates.title') }}</p>
                    <ul class="space-y-2 text-sm text-ink">
                        @foreach ($this->duplicateCustomers as $row)
                            <li class="space-y-1" wire:key="dup-c-{{ $row['customer']->id }}">
                                <p><span class="font-medium">{{ $row['customer']->name }}</span> · {{ __('leads.duplicates.customer') }}</p>
                                <p class="text-xs text-muted">{{ collect($row['reasons'])->map(fn ($reason) => __('leads.duplicates.reasons.'.$reason))->join(', ') }} · {{ $row['customer']->owner?->name }}</p>
                                @can('view', $row['customer'])
                                    <button type="button" wire:click="selectCustomer({{ $row['customer']->id }})" class="btn btn-secondary btn-sm" data-action="use-customer">{{ __('leads.duplicates.use_customer') }}</button>
                                @endcan
                            </li>
                        @endforeach
                        @foreach ($this->duplicateLeads as $other)
                            <li wire:key="dup-l-{{ $other->id }}">
                                <span class="font-mono">{{ $other->number }}</span> · {{ $other->companyLabel() }} · {{ $other->status->label() }} · {{ __('leads.duplicates.owner', ['name' => $other->owner?->name ?? __('leads.unassigned')]) }}
                                @can('view', $other)
                                    <a href="{{ route('leads.show', $other) }}" wire:navigate class="link ml-1">{{ __('leads.duplicates.open') }}</a>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                    @if ($this->needsOverride)
                        <div>
                            <label for="duplicate-override" class="form-label">{{ __('leads.duplicates.override_reason') }}</label>
                            <textarea wire:model="duplicateOverride" id="duplicate-override" rows="2" class="form-input" maxlength="500" aria-describedby="override-hint"></textarea>
                            <p id="override-hint" class="mt-1 text-xs text-muted">{{ __('leads.duplicates.override_hint') }}</p>
                            @error('duplicateOverride') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </section>
            @endif

            @if (! $wizard || $step === 4)
            <section class="card space-y-2 p-5" data-lead-actions>
                <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled" wire:target="save" data-action="save-lead">{{ __('common.save') }}</button>
                <button type="button" wire:click="save('another')" class="btn btn-secondary w-full" wire:loading.attr="disabled" wire:target="save" data-action="save-another">{{ __('leads.actions.save_another') }}</button>
                @if ($customerId)
                    <button type="button" wire:click="save('offer')" class="btn btn-secondary w-full" wire:loading.attr="disabled" wire:target="save" data-action="save-offer">{{ __('leads.actions.save_offer') }}</button>
                @endif
                <a href="{{ $leadId ? route('leads.show', $leadId) : route('leads.index') }}" wire:navigate class="btn btn-secondary w-full" data-action="cancel">{{ __('common.cancel') }}</a>
                <p class="pt-2 text-xs text-muted">{{ __('leads.shortcuts') }}</p>
                @if ($isNew)
                    <p class="text-xs text-muted" wire:loading.remove wire:target="autosave">{{ __('leads.draft.autosave_hint') }}</p>
                @endif
            </section>
            @else
                <p class="card p-5 text-xs text-muted" data-wizard-hint>{{ __('leads.wizard.save_hint') }} {{ __('leads.shortcuts') }}</p>
            @endif
        </aside>
    </form>
</div>
