<div class="space-y-6">
    @php
        $cp = $this->counterparty;
        $canEdit = auth()->user()->can('update', $cp);
        $canQualify = auth()->user()->can('qualify', $cp);
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $tabs = ['details' => __('counterparties.tabs.details'), 'contacts' => __('counterparties.tabs.contacts'), 'documents' => __('counterparties.tabs.documents'), 'ratings' => __('counterparties.tabs.ratings'), 'tenders' => __('counterparties.tabs.tenders'), 'activity' => __('counterparties.tabs.activity')];
    @endphp

    <a href="{{ route('counterparties.index') }}" wire:navigate class="link text-sm">&larr; {{ __('counterparties.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">{{ $cp->name }}</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @php([$tone, $icon] = $cp->status->badge())
                    <x-badge :tone="$tone" :icon="$icon">{{ $cp->status->label() }}</x-badge>
                    @if ($cp->is_supplier) <x-badge tone="outline">{{ __('counterparties.roles.supplier') }}</x-badge> @endif
                    @if ($cp->is_carrier) <x-badge tone="outline">{{ __('counterparties.roles.carrier') }}</x-badge> @endif
                    @if ($cp->rating !== null) <x-badge tone="silver" icon="star">{{ $cp->rating }}</x-badge> @endif
                    @if ($cp->organization?->is_customer)
                        @php($asCustomer = \App\Models\Customer::query()->find($cp->partner_id))
                        @if ($asCustomer && auth()->user()->can('view', $asCustomer))
                            <a href="{{ route('customers.show', $asCustomer) }}" wire:navigate data-also-customer><x-badge tone="dark">{{ __('organizations.also_customer') }}</x-badge></a>
                        @else
                            <x-badge tone="dark" data-also-customer>{{ __('organizations.also_customer') }}</x-badge>
                        @endif
                    @endif
                </div>
                @if ($cp->organization && auth()->user()->can('editRoles', $cp->organization))
                    <div class="mt-3"><livewire:partners.roles :partner-id="$cp->partner_id" :key="'roles-'.$cp->partner_id" /></div>
                @endif
                @if ($cp->status_reason)
                    <p class="mt-2 text-sm text-muted">{{ trans()->has('counterparties.status_reasons.'.$cp->status_reason) ? __('counterparties.status_reasons.'.$cp->status_reason) : $cp->status_reason }}</p>
                @endif
            </div>
            @if ($canQualify && $targets !== [])
                <form wire:submit="changeStatus" class="flex flex-wrap items-end gap-2" data-status-form>
                    <div>
                        <label for="cp-target" class="form-label">{{ __('counterparties.change_status') }}</label>
                        <select wire:model="statusTarget" id="cp-target" class="form-input">
                            <option value="">—</option>
                            @foreach ($targets as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="cp-reason" class="form-label">{{ __('counterparties.reason') }}</label>
                        <input wire:model="statusReason" id="cp-reason" type="text" class="form-input">
                    </div>
                    <button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button>
                </form>
            @endif
        </div>
    </header>

    <div>
        <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('counterparties.tabs_label') }}" class="flex gap-1 overflow-x-auto border-b border-line">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" id="tab-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                        @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>

        <section class="card mt-4 p-5" role="tabpanel" aria-labelledby="tab-{{ $tab }}">
            @if ($tab === 'details')
                <form wire:submit="saveDetails" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" novalidate>
                    @php($identityLocked = $cp->identityLocked())
                    @if ($identityLocked)
                        <p class="text-sm text-muted sm:col-span-2 lg:col-span-3" data-identity-locked>{{ __('organizations.identity_on_customer_card') }}</p>
                    @endif
                    @foreach (['name', 'legal_name', 'tax_id', 'country', 'city', 'phone', 'email', 'website'] as $field)
                        <div @class(['sm:col-span-2' => $field === 'name'])>
                            <label for="d-{{ $field }}" class="form-label">{{ __('counterparties.fields.'.$field) }}</label>
                            <input wire:model="details.{{ $field }}" id="d-{{ $field }}" type="text" class="form-input" @disabled(! $canEdit || $identityLocked)>
                            @error('details.'.$field) <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div>
                        <label for="d-fleet" class="form-label">{{ __('counterparties.fields.fleet_size') }}</label>
                        <input wire:model="details.fleet_size" id="d-fleet" type="number" min="0" class="form-input" @disabled(! $canEdit)>
                    </div>
                    <div>
                        <label for="d-locale" class="form-label">{{ __('counterparties.fields.preferred_locale') }}</label>
                        <select wire:model="details.preferred_locale" id="d-locale" class="form-input" @disabled(! $canEdit)>
                            <option value="">—</option>
                            @foreach (\App\Enums\Locale::available() as $locale)
                                <option value="{{ $locale->value }}">{{ $locale->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label for="d-address" class="form-label">{{ __('counterparties.fields.address') }}</label>
                        <textarea wire:model="details.address" id="d-address" rows="2" class="form-input" @disabled(! $canEdit || $identityLocked)></textarea>
                    </div>
                    <fieldset class="flex flex-wrap items-center gap-4 sm:col-span-2 lg:col-span-3">
                        <legend class="sr-only">{{ __('counterparties.fields.roles') }}</legend>
                        @foreach (['is_supplier' => 'counterparties.roles.supplier', 'is_carrier' => 'counterparties.roles.carrier', 'has_tir' => 'counterparties.fields.has_tir', 'has_adr' => 'counterparties.fields.has_adr'] as $field => $label)
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="details.{{ $field }}" type="checkbox" class="rounded border-line" @disabled(! $canEdit)> {{ __($label) }}</label>
                        @endforeach
                        @error('details.is_supplier') <p class="form-error w-full">{{ $message }}</p> @enderror
                    </fieldset>
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label for="d-notes" class="form-label">{{ __('counterparties.fields.notes') }}</label>
                        <textarea wire:model="details.notes" id="d-notes" rows="3" class="form-input" @disabled(! $canEdit)></textarea>
                    </div>
                    @if ($canEdit)
                        <div class="flex justify-end sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">{{ __('common.save') }}</button></div>
                    @endif
                </form>
            @elseif ($tab === 'contacts')
                <ul class="divide-y divide-line" data-contacts>
                    @forelse ($cp->contacts as $contact)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm" wire:key="ct-{{ $contact->id }}">
                            <span class="text-ink"><span class="font-semibold">{{ $contact->name }}</span>@if ($contact->is_primary) <x-badge tone="silver">{{ __('counterparties.contacts.primary') }}</x-badge>@endif
                                <span class="block text-muted">{{ collect([$contact->position, $contact->phone, $contact->email, $contact->telegram])->filter()->implode(' · ') }}</span></span>
                            @if ($canEdit)
                                <button type="button" wire:click="removeContact({{ $contact->id }})" wire:confirm="{{ __('counterparties.contacts.confirm_remove') }}" class="btn btn-secondary btn-sm">{{ __('counterparties.contacts.remove') }}</button>
                            @endif
                        </li>
                    @empty
                        <li><x-empty-state :title="__('counterparties.contacts.empty')" icon="contact" /></li>
                    @endforelse
                </ul>
                @if ($canEdit)
                    <form wire:submit="saveContact" class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-3" novalidate>
                        <div><label for="c-name" class="form-label">{{ __('counterparties.contacts.name') }}</label><input wire:model="contact.name" id="c-name" type="text" class="form-input">@error('contact.name') <p class="form-error">{{ $message }}</p> @enderror</div>
                        <div><label for="c-position" class="form-label">{{ __('counterparties.contacts.position') }}</label><input wire:model="contact.position" id="c-position" type="text" class="form-input"></div>
                        <div><label for="c-phone" class="form-label">{{ __('counterparties.fields.phone') }}</label><input wire:model="contact.phone" id="c-phone" type="text" class="form-input"></div>
                        <div><label for="c-email" class="form-label">{{ __('counterparties.fields.email') }}</label><input wire:model="contact.email" id="c-email" type="email" class="form-input">@error('contact.email') <p class="form-error">{{ $message }}</p> @enderror</div>
                        <div><label for="c-tg" class="form-label">{{ __('counterparties.contacts.telegram') }}</label><input wire:model="contact.telegram" id="c-tg" type="text" class="form-input"></div>
                        <div class="flex items-end gap-3">
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="contact.is_primary" type="checkbox" class="rounded border-line"> {{ __('counterparties.contacts.primary') }}</label>
                            <button type="submit" class="btn btn-primary ml-auto">{{ __('counterparties.contacts.add') }}</button>
                        </div>
                    </form>
                @endif
            @elseif ($tab === 'documents')
                <table class="min-w-full divide-y divide-line text-sm" data-documents>
                    <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                        <tr><th scope="col" class="py-2">{{ __('counterparties.documents.type') }}</th><th scope="col" class="py-2">{{ __('counterparties.documents.number') }}</th><th scope="col" class="py-2">{{ __('counterparties.documents.valid_until') }}</th><th scope="col" class="py-2">{{ __('counterparties.documents.verified') }}</th><th scope="col" class="py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($cp->documents as $document)
                            <tr wire:key="doc-{{ $document->id }}">
                                <td class="py-2 text-ink">{{ $document->type->label() }}</td>
                                <td class="py-2 font-mono text-ink">{{ $document->number ?? '—' }}</td>
                                <td class="py-2 text-ink">
                                    {{ $document->valid_until?->translatedFormat($dateFormat) ?? '—' }}
                                    @if ($document->isExpired()) <x-badge tone="outline" icon="warning">{{ __('counterparties.documents.expired') }}</x-badge>
                                    @elseif ($document->isExpiringSoon()) <x-badge tone="silver" icon="clock">{{ __('counterparties.documents.expiring') }}</x-badge> @endif
                                </td>
                                <td class="py-2 text-ink">{{ $document->verified_at ? $document->verifier?->name : '—' }}</td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if ($document->attachment)
                                        <a href="{{ route('attachments.download', $document->attachment) }}" target="_blank" rel="noopener" class="link">{{ __('counterparties.documents.open') }}</a>
                                    @endif
                                    @if ($canQualify && ! $document->verified_at)
                                        <button type="button" wire:click="verifyDocument({{ $document->id }})" class="btn btn-secondary btn-sm">{{ __('counterparties.documents.verify') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-empty-state :title="__('counterparties.documents.empty')" icon="agreements" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($canEdit)
                    <form wire:submit="addDocument" class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-4" novalidate>
                        <div>
                            <label for="doc-type" class="form-label">{{ __('counterparties.documents.type') }}</label>
                            <select wire:model="document.type" id="doc-type" class="form-input">
                                @foreach ($documentTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div><label for="doc-number" class="form-label">{{ __('counterparties.documents.number') }}</label><input wire:model="document.number" id="doc-number" type="text" class="form-input"></div>
                        <div><label for="doc-valid" class="form-label">{{ __('counterparties.documents.valid_until') }}</label><input wire:model="document.valid_until" id="doc-valid" type="date" class="form-input"></div>
                        <div><label for="doc-file" class="form-label">{{ __('counterparties.documents.file') }}</label><input wire:model="documentFile" id="doc-file" type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-input">@error('documentFile') <p class="form-error">{{ $message }}</p> @enderror</div>
                        <div class="flex justify-end sm:col-span-4"><button type="submit" class="btn btn-primary">{{ __('counterparties.documents.add') }}</button></div>
                    </form>
                @endif
            @elseif ($tab === 'ratings')
                <ul class="divide-y divide-line">
                    @forelse ($cp->ratings as $row)
                        <li class="py-3 text-sm" wire:key="rt-{{ $row->id }}">
                            <p class="text-ink">{{ __('counterparties.ratings.line', ['quality' => $row->quality, 'communication' => $row->communication, 'documents' => $row->documents]) }}@if ($row->on_time !== null) · {{ $row->on_time ? __('counterparties.ratings.on_time') : __('counterparties.ratings.late') }}@endif</p>
                            <p class="text-xs text-muted">{{ $row->rater?->name }} · {{ $row->created_at?->translatedFormat($dateFormat) }}@if ($row->comment) · {{ $row->comment }}@endif</p>
                        </li>
                    @empty
                        <li><x-empty-state :title="__('counterparties.ratings.empty')" icon="star" /></li>
                    @endforelse
                </ul>
                @can('rate', $cp)
                    <form wire:submit="rate" class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-5" novalidate>
                        @foreach (['quality', 'communication', 'documents'] as $field)
                            <div><label for="r-{{ $field }}" class="form-label">{{ __('counterparties.ratings.'.$field) }}</label>
                                <select wire:model="rating.{{ $field }}" id="r-{{ $field }}" class="form-input">@for ($i = 5; $i >= 1; $i--)<option value="{{ $i }}">{{ $i }}</option>@endfor</select></div>
                        @endforeach
                        <div><label for="r-ontime" class="form-label">{{ __('counterparties.ratings.on_time') }}</label>
                            <select wire:model="rating.on_time" id="r-ontime" class="form-input"><option value="">—</option><option value="1">{{ __('common.yes') }}</option><option value="0">{{ __('common.no') }}</option></select></div>
                        <div><label for="r-comment" class="form-label">{{ __('counterparties.ratings.comment') }}</label><input wire:model="rating.comment" id="r-comment" type="text" class="form-input"></div>
                        <div class="flex justify-end sm:col-span-5"><button type="submit" class="btn btn-primary">{{ __('counterparties.ratings.add') }}</button></div>
                    </form>
                @endcan
            @elseif ($tab === 'tenders')
                <ul class="divide-y divide-line">
                    @forelse ($this->invitations as $invitation)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm" wire:key="inv-{{ $invitation->id }}">
                            <a href="{{ route('tenders.show', $invitation->tender) }}" wire:navigate class="link font-mono">{{ $invitation->tender->number }}</a>
                            <span class="text-ink">{{ $invitation->tender->title }}</span>
                            <x-badge tone="outline">{{ $invitation->status->label() }}</x-badge>
                        </li>
                    @empty
                        <li><x-empty-state :title="__('counterparties.no_tenders')" icon="offers" /></li>
                    @endforelse
                </ul>
            @else
                <x-activity-log :entries="$cp->activities()->with('causer')->limit(100)->get()" />
            @endif
        </section>
    </div>
</div>
