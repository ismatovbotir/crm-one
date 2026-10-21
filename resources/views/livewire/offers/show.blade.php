<div class="space-y-6">
    @php
        $offer = $this->offer;
        $user = auth()->user();
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
    @endphp

    <a href="{{ route('offers.index') }}" wire:navigate class="link text-sm">&larr; {{ __('offers.back') }}</a>

    <x-flash />

    @if ($offer->supersededBy)
        <p class="rounded-md border border-line-strong bg-surface px-4 py-3 text-sm text-ink" role="status">
            {{ __('offers.show.superseded_by') }}
            <a href="{{ route('offers.show', $offer->supersededBy) }}" wire:navigate class="link">{{ $offer->supersededBy->displayNumber() }}</a>
        </p>
    @endif

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $offer->displayNumber() }}</p>
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">
                    @can('view', $offer->customer)
                        <a href="{{ route('customers.show', $offer->customer) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $offer->customer?->name }}</a>
                    @else
                        {{ $offer->customer?->name }}
                    @endcan
                </h2>
                @if ($offer->lead)
                    <p class="mt-1 text-sm text-muted" data-offer-lead>{{ __('offers.show.from_lead') }}
                        @can('view', $offer->lead)
                            <a href="{{ route('leads.show', $offer->lead) }}" wire:navigate class="link font-mono">{{ $offer->lead->number }}</a>
                        @else
                            <span class="font-mono">{{ $offer->lead->number }}</span>
                        @endcan
                    </p>
                @endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-offers.status-badge :status="$offer->status" />
                    @if ($offer->status->isOpen() && $offer->groups->isNotEmpty())
                        @if ($offer->selection_complete)
                            <x-badge tone="dark" icon="check" data-ready>{{ __('offers.ready_to_accept') }}</x-badge>
                        @else
                            <x-badge tone="outline" icon="clock">{{ __('offers.show.choices_pending') }}</x-badge>
                        @endif
                    @endif
                    @if ($this->approvalRequired && $offer->status->isEditable())
                        <x-badge tone="danger-outline" icon="warning">{{ __('offers.show.needs_approval') }}</x-badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2" data-offer-actions>
                @can('update', $offer)
                    <a href="{{ route('offers.edit', $offer) }}" wire:navigate class="btn btn-secondary">{{ __('common.edit') }}</a>
                @endcan
                @can('submit', $offer)
                    @if ($this->approvalRequired)
                        <button type="button" wire:click="submit" class="btn btn-primary" wire:loading.attr="disabled" data-action="submit">{{ __('offers.actions.submit') }}</button>
                    @endif
                @endcan
                @can('approve', $offer)
                    <button type="button" wire:click="openPanel('approve')" class="btn btn-primary" data-action="approve">{{ __('offers.actions.approve') }}</button>
                    <button type="button" wire:click="openPanel('return')" class="btn btn-secondary" data-action="return">{{ __('offers.actions.return') }}</button>
                @endcan
                @can('send', $offer)
                    @if (! $this->approvalRequired || $offer->status === \App\Enums\OfferStatus::Approved)
                        <button type="button" wire:click="openPanel('send')" class="btn btn-primary" data-action="send">{{ __('offers.actions.send') }}</button>
                    @endif
                @endcan
                @can('recordDecision', $offer)
                    @if ($offer->groups->isNotEmpty())
                        <button type="button" wire:click="openPanel('selection')" class="btn btn-secondary" data-action="selection">{{ __('offers.actions.record_choice') }}</button>
                    @endif
                    <button type="button" wire:click="openPanel('accept')" class="btn btn-primary" data-action="accept">{{ __('offers.actions.accept') }}</button>
                    <button type="button" wire:click="openPanel('reject')" class="btn btn-secondary" data-action="reject">{{ __('offers.actions.reject') }}</button>
                @endcan
                @can('revise', $offer)
                    <button type="button" wire:click="revise" class="btn btn-secondary" data-action="revise">{{ __('offers.actions.revise') }}</button>
                @endcan
                @if ($offer->order)
                    @can('view', $offer->order)
                        <a href="{{ route('orders.show', $offer->order) }}" wire:navigate class="btn btn-secondary" data-order-link><x-icon name="orders" class="size-4" /> {{ $offer->order->number }}</a>
                    @endcan
                @else
                    @can('createOrder', $offer)
                        <button type="button" wire:click="createOrder" class="btn btn-primary" data-action="create-order">{{ __('offers.actions.create_order') }}</button>
                    @endcan
                @endif
                @can('print', $offer)
                    <a href="{{ route('offers.print', $offer) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('offers.actions.print') }}</a>
                @endcan
                @can('cancel', $offer)
                    <button type="button" wire:click="openPanel('cancel')" class="btn btn-danger-outline" data-action="cancel">{{ __('offers.actions.cancel') }}</button>
                @endcan
            </div>
        </div>

        {{-- Summary (always visible above the tabs): key facts only; the rest is on the Header tab. --}}
        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4" data-document-summary>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.owner') }}</dt><dd class="mt-1 text-ink">{{ $offer->owner?->name }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.valid_until') }}</dt><dd class="mt-1 text-ink">{{ $offer->valid_until->translatedFormat($dateFormat) }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.total') }}</dt><dd class="mt-1 font-semibold text-ink"><x-offers.total :offer="$offer" /></dd></div>
        </dl>

        @if ($this->missingGroups !== [])
            <p class="mt-4 rounded-md border border-line-strong bg-neutral-50 px-3 py-2 text-sm text-ink" data-missing-groups>{{ __('offers.show.missing_choices', ['groups' => implode('; ', $this->missingGroups)]) }}</p>
        @endif
        @if (collect($this->issues)->contains('level', 'blocker'))
            <p class="mt-4 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800">{{ __('offers.show.has_blockers') }}</p>
        @endif
    </header>

    {{-- Action panels --}}
    @if ($panel !== '')
        <section class="card border-2 border-brand-600 p-5" role="dialog" aria-labelledby="panel-title" data-panel="{{ $panel }}">
            <h3 id="panel-title" class="font-semibold text-ink">{{ __('offers.panels.'.$panel) }}</h3>

            @if ($panel === 'selection')
                <p class="mt-1 text-xs text-muted">{{ __('offers.panels.selection_help') }}</p>
                <div class="mt-4 space-y-4">
                    @foreach ($this->blocks->where('type', 'group') as $block)
                        @php
                            $group = $block['group'];
                        @endphp
                        <fieldset wire:key="choice-{{ $group->id }}" class="rounded-md border border-line p-3">
                            <legend class="px-1 text-sm font-semibold text-ink">{{ $block['number'] }}. {{ $group->title }} <span class="font-normal text-muted">({{ trans_choice('offers.totals.group_summary', $block['lines']->count(), ['count' => $block['lines']->count(), 'min' => $group->min_select, 'max' => $group->max_select]) }})</span></legend>
                            @if ($group->max_select === 1)
                                <label class="flex items-center gap-2 py-1 text-sm text-muted">
                                    <input type="radio" wire:model="choices.{{ $group->id }}" value="" class="size-4 accent-brand-600"> {{ __('offers.panels.no_choice') }}
                                </label>
                            @endif
                            @foreach ($block['lines'] as $line)
                                <label class="flex items-center gap-2 py-1 text-sm text-ink" wire:key="choice-line-{{ $line->id }}">
                                    <input type="{{ $group->max_select === 1 ? 'radio' : 'checkbox' }}" wire:model="choices.{{ $group->id }}" value="{{ $line->id }}" class="size-4 accent-brand-600">
                                    <span class="font-bold">{{ $block['number'] }}{{ $line->option_label }}</span> {{ $line->name }}
                                    <span class="text-muted">{{ $offer->money($offer->prices_include_vat ? $line->line_gross_minor : $line->line_net_minor)->format() }}</span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endforeach
                </div>
            @endif

            @if ($panel === 'accept' && $this->missingGroups !== [])
                <p class="mt-3 text-sm text-brand-800" data-accept-blocked>{{ __('offers.show.missing_choices', ['groups' => implode('; ', $this->missingGroups)]) }}</p>
            @endif

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @if (in_array($panel, ['send', 'selection', 'accept'], true))
                    <div>
                        <label for="panel-channel" class="form-label">{{ __('offers.fields.channel') }}</label>
                        <select wire:model.live="channel" id="panel-channel" class="form-input">
                            <option value="">—</option>
                            @foreach ($panel === 'send' ? $sendingChannels : $internalChannels as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </select>
                        @error('channel') <p class="form-error">{{ $message }}</p> @enderror
                        @if ($panel === 'send')
                            <p class="mt-1 text-xs text-muted">{{ __('offers.panels.send_help') }}</p>
                        @endif
                    </div>
                @endif
                @if (in_array($panel, ['selection', 'accept'], true))
                    <div>
                        <label for="panel-note" class="form-label">{{ __('offers.fields.note') }}</label>
                        <input wire:model="note" id="panel-note" type="text" class="form-input">
                        @error('note') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="panel-evidence" class="form-label">{{ __('offers.fields.evidence') }}</label>
                        <input wire:model="evidence" id="panel-evidence" type="text" class="form-input" placeholder="{{ __('offers.panels.evidence_hint') }}">
                        @error('evidence') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if ($panel === 'reject')
                    <div>
                        <label for="panel-reason" class="form-label">{{ __('offers.fields.reject_reason') }}</label>
                        <select wire:model="rejectReason" id="panel-reason" class="form-input">
                            <option value="">—</option>
                            @foreach ($rejectReasons as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('rejectReason') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                @if (in_array($panel, ['reject', 'cancel', 'approve', 'return'], true))
                    <div class="sm:col-span-2">
                        <label for="panel-comment" class="form-label">{{ $panel === 'cancel' ? __('offers.fields.cancel_reason') : __('offers.fields.comment') }}</label>
                        <textarea wire:model="comment" id="panel-comment" rows="2" class="form-input"></textarea>
                        @error('comment') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="closePanel" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                @php
                    $submitAction = ['send' => 'send', 'selection' => 'saveSelection', 'accept' => 'accept', 'reject' => 'reject', 'cancel' => 'cancel', 'approve' => 'approve', 'return' => 'returnToDraft'][$panel];
                @endphp
                <button type="button" wire:click="{{ $submitAction }}" class="btn btn-primary" wire:loading.attr="disabled" @disabled($panel === 'accept' && $this->missingGroups !== []) data-confirm-panel>{{ __('offers.panels.confirm_'.$panel) }}</button>
            </div>
        </section>
    @endif

    {{-- Standard document tabs (user decision 2026-10-20): Header | Items | Other. --}}
    <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
        @if ($tab === 'header')
            <section class="card p-5" aria-label="{{ __('common.document_tabs.header') }}" data-offer-header>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.contact') }}</dt><dd class="mt-1 text-ink">{{ $offer->contact?->fullName() ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.currency') }}</dt><dd class="mt-1 text-ink">{{ $offer->currency }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.payment_terms_days') }}</dt><dd class="mt-1 text-ink">{{ $offer->payment_terms_days ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.document_locale') }}</dt><dd class="mt-1 text-ink">{{ \App\Enums\Locale::tryFrom((string) $offer->document_locale)?->label() ?? __('offers.builder.customer_language') }}</dd></div>
                    @if ($offer->sent_at)
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.show.sent') }}</dt><dd class="mt-1 text-ink">{{ $offer->sent_at->translatedFormat($dateTimeFormat) }} · {{ $offer->sent_channel?->label() }}</dd></div>
                    @endif
                    @if ($offer->accepted_at)
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.show.accepted') }}</dt><dd class="mt-1 text-ink">{{ $offer->accepted_at->translatedFormat($dateTimeFormat) }} · {{ $offer->accepted_via?->label() }}</dd></div>
                    @endif
                    @if ($offer->rejected_at)
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.show.rejected') }}</dt><dd class="mt-1 text-ink">{{ \App\Enums\OfferRejectReason::tryFrom((string) $offer->reject_reason_code)?->label() }}@if ($offer->reject_reason_text): {{ $offer->reject_reason_text }}@endif</dd></div>
                    @endif
                </dl>
                <h3 class="mt-6 border-t border-line pt-4 font-semibold text-ink">{{ __('offers.show.terms') }}</h3>
                <dl class="mt-3 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    @foreach (['payment_terms_text', 'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes'] as $field)
                        <div wire:key="hf-{{ $field }}"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.'.$field) }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $offer->{$field} ?: '—' }}</dd></div>
                    @endforeach
                    @if ($offer->internal_notes)
                        <div class="sm:col-span-2"><dt class="flex items-center gap-1 text-xs font-medium tracking-wide text-muted uppercase"><x-icon name="lock" class="size-3.5" /> {{ __('offers.fields.internal_notes') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $offer->internal_notes }}</dd></div>
                    @endif
                </dl>
            </section>
        @elseif ($tab === 'items')
            <section class="card p-5" aria-label="{{ __('common.document_tabs.items') }}">
                @if ($offer->lines->isEmpty())
                    <x-empty-state :title="__('offers.builder.empty')" icon="offers" />
                @else
                    @php
                        $imagesDefault = \App\Support\BusinessSettings::bool('documents.show_item_images');
                    @endphp
                    <p class="mb-2 flex flex-wrap items-center gap-3 text-sm" data-print-images>
                        <span class="text-muted">{{ __('offers.show.print_pictures') }}:</span>
                        <a href="{{ route('offers.print', ['offer' => $offer, 'images' => $imagesDefault ? 0 : 1]) }}" target="_blank" rel="noopener" class="link">{{ $imagesDefault ? __('offers.show.print_without_pictures') : __('offers.show.print_with_pictures') }}</a>
                    </p>
                    @if ($offer->groups->isNotEmpty())
                        <p class="mb-4 flex flex-wrap items-center gap-3 text-sm" data-print-modes>
                            <span class="text-muted">{{ __('offers.actions.print') }}:</span>
                            <a href="{{ route('offers.print', ['offer' => $offer, 'selection' => 'blank']) }}" target="_blank" rel="noopener" class="link">{{ __('offers.show.print_blank') }}</a>
                            <a href="{{ route('offers.print', ['offer' => $offer, 'selection' => 'current']) }}" target="_blank" rel="noopener" class="link">{{ __('offers.show.print_current') }}</a>
                            @if ($offer->selection_complete)
                                <a href="{{ route('offers.print', ['offer' => $offer, 'selection' => 'selected']) }}" target="_blank" rel="noopener" class="link">{{ __('offers.show.print_selected') }}</a>
                            @endif
                        </p>
                    @endif
                    <x-offers.customer-view :offer="$offer" :blocks="$this->blocks" :selected="$offer->selections->pluck('offer_line_id')->map(fn ($id) => (int) $id)->all()" :totals="$this->totals" />
                @endif
            </section>
        @else
            <livewire:offers.history :offer-id="$offer->id" :key="'offer-history-'.$offer->id.'-'.$offer->updated_at?->getTimestamp()" />
        @endif
    </x-tabs>
</div>
