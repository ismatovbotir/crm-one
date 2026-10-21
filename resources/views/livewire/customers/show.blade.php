<div class="space-y-6">
    @php
        $customer = $this->customer;
        $canManageContacts = auth()->user()->can('manageContacts', $customer);
        $tabs = ['overview' => __('customers.tabs.overview'), 'contacts' => __('customers.tabs.contacts'), 'finance' => __('customers.tabs.finance'), 'portal' => __('portal.access.tab'), 'timeline' => __('timeline.title'), 'activity' => __('activity.title')];
        if (! auth()->user()->can('managePortal', $customer)) {
            unset($tabs['portal']);
        }
        // Spec 04 section 17: Telegram groups linked to this company (and their members).
        $telegramChats = \App\Models\TelegramChat::query()->where('customer_id', $customer->id)->whereNull('merged_into_id')->orderByDesc('last_message_at')->get()->filter(fn ($chat) => auth()->user()->can('view', $chat));
        // Spec 09: equipment tab for the service team and the customer's owner.
        if (auth()->user()->can('viewAny', \App\Models\CustomerAsset::class) || auth()->user()->can('register', [\App\Models\CustomerAsset::class, $customer])) {
            $tabs['equipment'] = __('service.tab');
        }
        if ($telegramChats->isNotEmpty()) {
            $tabs['telegram'] = __('telegram.groups.tab');
        }
    @endphp

    <a href="{{ route('customers.index') }}" wire:navigate class="link text-sm">&larr; {{ __('customers.back') }}</a>

    <x-flash />

    @if ($customer->trashed())
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-line-strong bg-surface px-4 py-3 text-sm text-ink" role="status">
            <span class="flex items-center gap-2"><x-icon name="archive" class="size-4" /> {{ __('customers.archived_banner') }}</span>
            @can('restore', $customer)
                <button type="button" wire:click="restore" class="btn btn-dark px-3 py-1.5">{{ __('common.restore') }}</button>
            @endcan
        </div>
    @elseif ($customer->isDeletedInOneC())
        <div class="rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="status">{{ __('integration.deleted_in_1c_banner') }}</div>
    @endif

    <header class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">{{ $customer->name }}</h2>
            @if ($customer->legal_name)
                <p class="text-sm text-muted">{{ $customer->legal_name }}</p>
            @endif
            <div class="mt-3 flex flex-wrap gap-2">
                @if ($customer->sync_with_1c)
                    <x-sync-badge :status="$customer->sync_status" />
                @else
                    <x-badge tone="outline" data-sync-off>{{ __('customers.sync_off_badge') }}</x-badge>
                @endif
                <x-badge tone="outline">{{ $customer->origin->label() }}</x-badge>
                @if ($customer->has_duplicate_tax_id)
                    <x-badge tone="danger-outline" icon="warning">{{ __('integration.duplicate_tax_id') }}</x-badge>
                @endif
                {{-- Spec 03 section 22: one organization, several roles. --}}
                @php($profile = $customer->supplierProfile)
                @foreach ($customer->otherRoles() as $role)
                    @if ($profile && auth()->user()->can('view', $profile))
                        <a href="{{ route('counterparties.show', $profile) }}" wire:navigate data-also-role="{{ $role }}"><x-badge tone="dark">{{ __('organizations.also_'.$role) }}</x-badge></a>
                    @else
                        <x-badge tone="dark" data-also-role="{{ $role }}">{{ __('organizations.also_'.$role) }}</x-badge>
                    @endif
                @endforeach
            </div>
            {{-- Spec 03 section 24: add or remove roles of this partner. --}}
            @can('editRoles', \App\Models\Partner::withTrashed()->find($customer->id))
                <div class="mt-3"><livewire:partners.roles :partner-id="$customer->id" :key="'roles-'.$customer->id" /></div>
            @endcan
        </div>
        <div class="flex shrink-0 flex-wrap gap-2">
            <x-customers.create-menu :customer="$customer" />
            @can('edit', $customer)
                <a href="{{ route('customers.edit', $customer) }}" wire:navigate class="btn btn-primary">{{ __('common.edit') }}</a>
            @endcan
            @if (! $customer->is_supplier && ! $customer->is_carrier && auth()->user()->can('create', \App\Models\Counterparty::class))
                <button type="button" wire:click="addSupplierRole('supplier')" class="btn btn-secondary" data-action="add-supplier-role">{{ __('organizations.make_supplier') }}</button>
                <button type="button" wire:click="addSupplierRole('carrier')" class="btn btn-secondary" data-action="add-carrier-role">{{ __('organizations.make_carrier') }}</button>
            @endif
            @can('archive', $customer)
                <button type="button" wire:click="archive" wire:confirm="{{ __('customers.archive_confirm', ['name' => $customer->name]) }}" class="btn btn-danger-outline"><x-icon name="archive" class="size-4" /> {{ __('common.archive') }}</button>
            @endcan
        </div>
    </header>

    {{-- 1C exchange (Spec 02 rev. 3): 1C polls the CRM outbox; the CRM never calls 1C. --}}
    @php($exchange = $this->exchange)
    <section class="card p-5" aria-labelledby="sync-heading" data-customer-sync>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0 space-y-1 text-sm">
                <h3 id="sync-heading" class="flex flex-wrap items-center gap-2 font-semibold text-ink">
                    {{ __('integration.customer_sync.title') }} <x-sync-badge :status="$customer->sync_status" />
                </h3>
                @if ($customer->sync_status === \App\Enums\SyncStatus::Pending && $customer->sync_queued_at)
                    <p class="text-ink" data-pending-since>{{ __('integration.customer_sync.pending_since', ['time' => $customer->sync_queued_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}</p>
                @endif
                @if ($customer->sync_status === \App\Enums\SyncStatus::Pending && $exchange['is_stale'])
                    <p class="flex items-center gap-1 text-brand-700" role="alert" data-poll-stale>
                        <x-icon name="warning" class="size-4 shrink-0" />
                        {{ $exchange['last_poll']
                            ? __('integration.customer_sync.poll_stale', ['time' => $exchange['last_poll']->diffForHumans(), 'minutes' => $exchange['minutes']])
                            : __('integration.customer_sync.poll_never') }}
                    </p>
                @endif
                @if (in_array($customer->sync_status, [\App\Enums\SyncStatus::Failed, \App\Enums\SyncStatus::NotSynced], true) && $customer->sync_error)
                    <p class="text-ink" data-sync-error>
                        {{ trans()->has('integration.customer_sync.not_pushable.'.$customer->sync_error) ? __('integration.customer_sync.not_pushable.'.$customer->sync_error) : $customer->sync_error }}
                    </p>
                    @foreach ((array) $customer->sync_error_fields as $field => $messages)
                        <p class="text-xs text-muted" wire:key="sync-field-{{ $field }}">{{ trans()->has('customers.fields.'.$field) ? __('customers.fields.'.$field) : $field }}: {{ implode(', ', (array) $messages) }}</p>
                    @endforeach
                @endif
                <dl class="flex flex-wrap gap-x-6 gap-y-1 pt-1 text-xs text-muted">
                    <div><dt class="inline">{{ __('integration.onec_code') }}:</dt> <dd class="inline font-mono text-ink">{{ $customer->external_code ?? '—' }}</dd></div>
                    <div><dt class="inline">{{ __('integration.customer_sync.last_synced') }}:</dt> <dd class="inline text-ink">{{ $customer->synced_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) ?? __('common.never') }}</dd></div>
                    <div><dt class="inline">{{ __('integration.customer_sync.last_contact') }}:</dt> <dd class="inline text-ink">{{ $exchange['last_poll']?->diffForHumans() ?? __('common.never') }}</dd></div>
                </dl>
            </div>
            @can('resync', $customer)
                <button type="button" wire:click="resync" class="btn btn-secondary shrink-0" wire:loading.attr="disabled" data-resync>
                    <x-icon name="sync" class="size-4" /> {{ __('integration.customer_sync.resync') }}
                </button>
            @endcan
        </div>
    </section>

    <div>
        <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('customers.tabs.label') }}" class="flex gap-1 overflow-x-auto border-b border-line">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                        @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>
                    {{ $label }}
                    @if ($key === 'contacts')
                        <span class="ml-1 rounded-full bg-sidebar px-1.5 text-xs text-ink">{{ $this->contacts->count() }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        @if ($tab === 'overview')
            <section id="panel-overview" role="tabpanel" aria-labelledby="tab-overview" class="card mt-4">
                <dl class="grid gap-x-6 gap-y-4 p-6 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        'tax_id' => $customer->tax_id,
                        'legal_type' => $customer->legal_type->label(),
                        'owner_id' => $customer->owner?->name,
                        'currency' => $customer->currency,
                        'payment_terms_days' => $customer->payment_terms_days !== null ? trans_choice('customers.days', $customer->payment_terms_days, ['count' => $customer->payment_terms_days]) : null,
                        'country' => $customer->country,
                        'city' => $customer->city,
                        'address' => $customer->address,
                        'postal_code' => $customer->postal_code,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                        'website' => $customer->website,
                        'industry' => $customer->industry,
                        'preferred_locale' => \App\Enums\Locale::tryFrom((string) $customer->preferred_locale)?->label() ?? __('customers.no_preference'),
                        'sale_type' => $customer->sale_type->label(),
                    ] as $field => $value)
                        <div wire:key="ov-{{ $field }}">
                            <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('customers.fields.'.$field) }}</dt>
                            <dd class="mt-1 break-words text-ink">{{ filled($value) ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('customers.primary_contact') }}</dt>
                        <dd class="mt-1 text-ink">{{ $customer->primaryContact?->fullName() ?? __('customers.contacts.no_primary') }}</dd>
                    </div>
                    @if ($customer->acquisition_source)
                        <div data-acquisition>
                            <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('leads.conversion.acquisition') }}</dt>
                            <dd class="mt-1 text-ink">
                                {{ \App\Enums\LeadSource::tryFrom($customer->acquisition_source)?->label() ?? $customer->acquisition_source }}@if ($customer->acquisition_campaign) · {{ $customer->acquisition_campaign }}@endif
                                @if ($customer->sourceLead)
                                    · @can('view', $customer->sourceLead)<a href="{{ route('leads.show', $customer->sourceLead) }}" wire:navigate class="link font-mono">{{ $customer->sourceLead->number }}</a>@else<span class="font-mono">{{ $customer->sourceLead->number }}</span>@endcan
                                @endif
                            </dd>
                        </div>
                    @endif
                    @if ($customer->notes)
                        <div class="sm:col-span-2 lg:col-span-3">
                            <dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('customers.fields.notes') }}</dt>
                            <dd class="mt-1 whitespace-pre-line text-ink">{{ $customer->notes }}</dd>
                        </div>
                    @endif
                </dl>
            </section>
            @if ($this->interests->isNotEmpty())
                <section class="card mt-4" aria-labelledby="customer-interests" data-customer-interests>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-6 py-4">
                        <h3 id="customer-interests" class="font-semibold text-ink">{{ __('leads.conversion.interests_title') }}</h3>
                        @can('create', \App\Models\Offer::class)
                            @if (! $customer->trashed())
                                <button type="button" wire:click="createOfferFromInterests" wire:loading.attr="disabled" class="btn btn-secondary btn-sm" data-action="offer-from-interests">{{ __('leads.conversion.offer_from_interests') }}</button>
                            @endif
                        @endcan
                    </div>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($this->interests as $interest)
                            <li class="flex justify-between gap-3 px-6 py-3" wire:key="interest-{{ $interest->id }}">
                                <span class="text-ink">{{ $interest->item?->name }} <span class="font-mono text-xs text-muted">{{ $interest->item?->sku }}</span></span>
                                <span class="whitespace-nowrap text-muted">{{ \App\Support\Quantity::trimmed((string) $interest->quantity) }} {{ $interest->item?->unit?->code }} · {{ $interest->lead?->number }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @elseif ($tab === 'contacts')
            <section id="panel-contacts" role="tabpanel" aria-labelledby="tab-contacts" class="card mt-4">
                <div class="flex items-center justify-between border-b border-line px-6 py-4">
                    <h3 class="text-base font-semibold text-ink">{{ __('customers.tabs.contacts') }}</h3>
                    @if ($canManageContacts && ! $showContactForm)
                        <button type="button" wire:click="newContact" class="btn btn-primary"><x-icon name="plus" class="size-4" /> {{ __('customers.contacts.new') }}</button>
                    @endif
                </div>

                @if ($this->contacts->isNotEmpty() && $this->contacts->where('is_primary', true)->isEmpty())
                    <p class="flex items-center gap-2 border-b border-line bg-neutral-50 px-6 py-2 text-sm text-muted"><x-icon name="warning" class="size-4" /> {{ __('customers.contacts.no_primary_prompt') }}</p>
                @endif

                @if ($showContactForm)
                    <form wire:submit="saveContact" class="grid gap-4 border-b border-line bg-neutral-50 p-6 sm:grid-cols-2" novalidate aria-labelledby="contact-form-title">
                        <h4 id="contact-form-title" class="text-sm font-semibold text-ink sm:col-span-2">{{ $contactId ? __('customers.contacts.edit') : __('customers.contacts.new') }}</h4>
                        @foreach (['first_name' => 'text', 'last_name' => 'text', 'position' => 'text', 'email' => 'email', 'phone' => 'tel'] as $field => $inputType)
                            <div wire:key="contact-field-{{ $field }}">
                                <label for="contact-{{ $field }}" class="form-label">{{ __('customers.contacts.fields.'.$field) }}</label>
                                <input wire:model="{{ $field }}" id="contact-{{ $field }}" type="{{ $inputType }}" class="form-input">
                                @error($field) <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                        <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink">
                            <input wire:model="is_primary" type="checkbox" class="size-4 accent-brand-600"> {{ __('customers.contacts.primary') }}
                        </label>
                        <div class="sm:col-span-2">
                            <label for="contact-notes" class="form-label">{{ __('customers.fields.notes') }}</label>
                            <textarea wire:model="contact_notes" id="contact-notes" rows="2" class="form-input"></textarea>
                        </div>
                        <div class="flex justify-end gap-3 sm:col-span-2">
                            <button type="button" wire:click="cancelContact" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button>
                        </div>
                    </form>
                @endif

                <ul class="divide-y divide-line">
                    @forelse ($this->contacts as $contact)
                        <li wire:key="contact-{{ $contact->id }}" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 font-medium text-ink">
                                    {{ $contact->fullName() }}
                                    @if ($contact->is_primary)
                                        <x-badge tone="dark" icon="star">{{ __('customers.contacts.primary') }}</x-badge>
                                    @endif
                                </p>
                                <p class="text-sm text-muted">{{ collect([$contact->position, $contact->email, $contact->phone])->filter()->join(' · ') }}</p>
                            </div>
                            @if ($canManageContacts)
                                <div class="flex shrink-0 gap-2">
                                    @unless ($contact->is_primary)
                                        <button type="button" wire:click="makePrimary({{ $contact->id }})" class="btn btn-secondary px-3 py-1.5">{{ __('customers.contacts.make_primary') }}</button>
                                    @endunless
                                    <button type="button" wire:click="editContact({{ $contact->id }})" class="btn btn-secondary px-3 py-1.5">{{ __('common.edit') }}</button>
                                    <button type="button" wire:click="archiveContact({{ $contact->id }})" wire:confirm="{{ __('customers.contacts.archive_confirm', ['name' => $contact->fullName()]) }}" class="btn btn-danger-outline px-3 py-1.5">{{ __('common.archive') }}</button>
                                </div>
                            @endif
                        </li>
                    @empty
                        <li><x-empty-state :title="__('customers.contacts.empty')" icon="contact" /></li>
                    @endforelse
                </ul>
            </section>
        @elseif ($tab === 'portal' && auth()->user()->can('managePortal', $customer))
            <section id="panel-portal" role="tabpanel" aria-labelledby="tab-portal" class="mt-4">
                <livewire:customers.portal-access :customer-id="$customer->id" :key="'portal-'.$customer->id" />
                <div class="mt-4"><livewire:customers.portal-catalog-rules :customer-id="$customer->id" :key="'portal-rules-'.$customer->id" /></div>
            </section>
        @elseif ($tab === 'finance')
            <section id="panel-finance" role="tabpanel" aria-labelledby="tab-finance" class="mt-4">
                <livewire:customers.finance :customer-id="$customer->id" :key="'finance-'.$customer->id" />
            </section>
        @elseif ($tab === 'equipment' && isset($tabs['equipment']))
            <section id="panel-equipment" role="tabpanel" aria-labelledby="tab-equipment" class="mt-4">
                <livewire:customers.equipment :customer-id="$customer->id" :key="'equipment-'.$customer->id" />
            </section>
        @elseif ($tab === 'telegram' && $telegramChats->isNotEmpty())
            <section id="panel-telegram" role="tabpanel" aria-labelledby="tab-telegram" class="mt-4 space-y-4" data-customer-telegram>
                <div class="card flex flex-wrap items-center justify-between gap-3 p-5" data-telegram-service-updates>
                    <div>
                        <p class="font-semibold text-ink">{{ __('service.telegram.customer_setting') }}</p>
                        <p class="text-sm text-muted">{{ __('service.telegram.customer_setting_hint') }}</p>
                        @unless (\App\Support\BusinessSettings::bool('service.telegram_group_updates'))
                            <p class="mt-1 text-xs text-brand-800">{{ __('service.telegram.globally_off') }}</p>
                        @endunless
                    </div>
                    <div class="flex items-center gap-3">
                        <x-badge tone="outline">{{ $customer->telegram_service_updates ? __('service.telegram.state_on') : __('service.telegram.state_off') }}</x-badge>
                        @can('update', $customer)
                            <button type="button" wire:click="toggleTelegramServiceUpdates" class="btn btn-secondary btn-sm" data-action="toggle-telegram-service">{{ $customer->telegram_service_updates ? __('service.telegram.turn_off') : __('service.telegram.turn_on') }}</button>
                        @endcan
                    </div>
                </div>
                @foreach ($telegramChats as $telegramChat)
                    <livewire:telegram.chat-panel :chat-id="$telegramChat->id" :key="'tg-panel-'.$telegramChat->id" />
                @endforeach
            </section>
        @elseif ($tab === 'timeline')
            <section id="panel-timeline" role="tabpanel" aria-labelledby="tab-timeline" class="card mt-4">
                <ol class="divide-y divide-line" data-customer-timeline>
                    @forelse ($this->timeline as $entry)
                        <li class="px-5 py-3 text-sm" wire:key="ct-{{ $loop->index }}" data-timeline-type="{{ $entry['type'] }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-ink">
                                    @if ($entry['url'])<a href="{{ $entry['url'] }}" wire:navigate class="font-semibold hover:text-brand-700 hover:underline">{{ $entry['label'] }}</a>@else<span class="font-semibold">{{ $entry['label'] }}</span>@endif
                                    <span class="text-muted">· {{ $entry['user'] ?? __('activity.system') }}</span>
                                </p>
                                <time class="text-xs text-muted" datetime="{{ $entry['at']?->toIso8601String() }}">{{ $entry['at']?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</time>
                            </div>
                            @if ($entry['detail']) <p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $entry['detail'] }}</p> @endif
                        </li>
                    @empty
                        <li><x-empty-state :title="__('activity.empty')" icon="clock" /></li>
                    @endforelse
                </ol>
            </section>
        @else
            <section id="panel-activity" role="tabpanel" aria-labelledby="tab-activity" class="card mt-4">
                <x-activity-log :entries="$this->activities" />
            </section>
        @endif
    </div>
</div>
