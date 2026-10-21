<div class="space-y-6">
    @php
        $offer = $this->offer;
        $formatter = new \App\Documents\DocumentFormatter(\App\Enums\Locale::current());
        $money = fn (?int $minor) => $minor === null ? '—' : $offer->money($minor)->format();
        $percent = fn (int $bp) => $formatter->percent(bcdiv((string) $bp, '100', 2));
        $blockCount = $this->blocks->count();
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('offers.show', $offer) }}" wire:navigate class="link text-sm">&larr; {{ __('offers.builder.back_to_offer') }}</a>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('offers.print', $offer) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('offers.actions.print') }}</a>
            <a href="{{ route('offers.show', $offer) }}" wire:navigate class="btn btn-primary">{{ __('offers.builder.done') }}</a>
        </div>
    </div>

    <x-flash />

    {{-- Summary (always visible above the tabs): number, customer, status, total, checks. --}}
    <header class="card flex flex-col gap-4 p-5 lg:flex-row lg:items-start lg:justify-between" data-document-summary>
        <div class="min-w-0">
            <p class="font-mono text-sm text-muted">{{ $offer->displayNumber() }}</p>
            <h2 class="text-xl font-semibold break-words text-ink">{{ $offer->customer?->name }}</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                <x-offers.status-badge :status="$offer->status" />
                @if (collect($this->issues)->contains('level', 'blocker'))
                    <x-badge tone="danger-outline" icon="warning" data-summary-blockers>{{ __('offers.show.has_blockers') }}</x-badge>
                @endif
                @if ($this->approval['required'])
                    <x-badge tone="danger-outline" icon="warning">{{ __('offers.show.needs_approval') }}</x-badge>
                @endif
            </div>
        </div>
        <dl class="grid shrink-0 grid-cols-2 gap-x-6 gap-y-2 text-sm">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.valid_until') }}</dt><dd class="mt-1 text-ink">{{ $offer->valid_until->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('offers.fields.total') }}</dt><dd class="mt-1 font-semibold text-ink tabular-nums" data-summary-total><x-offers.total :offer="$offer" /></dd></div>
        </dl>
    </header>

    {{--
        Standard document tabs (user decision 2026-10-20): Header | Items | Customer preview | Other.
        One component state for all tabs: unsaved header inputs survive a switch (marker on the tab).
        The catalog search is the isolated offers.item-picker, so typing never re-renders this page.
    --}}
    <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
        @if ($tab === 'header')
            <section class="card" aria-labelledby="details-heading" data-offer-header>
                <h3 id="details-heading" class="border-b border-line px-5 py-4 font-semibold text-ink">{{ __('offers.builder.details') }}</h3>
                <form wire:submit="saveHeader" class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" novalidate>
                    <div>
                        <label for="contact_id" class="form-label">{{ __('offers.fields.contact_id') }}</label>
                        <select wire:model="contact_id" id="contact_id" class="form-input">
                            <option value="">{{ __('offers.no_contact') }}</option>
                            @foreach ($this->contacts as $contact)
                                <option value="{{ $contact->id }}" wire:key="c-{{ $contact->id }}">{{ $contact->fullName() }}</option>
                            @endforeach
                        </select>
                        @error('contact_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="currency" class="form-label">{{ __('offers.fields.currency') }}</label>
                        <select wire:model="currency" id="currency" class="form-input" @disabled($offer->lines->isNotEmpty())>
                            @foreach ($currencies as $code)
                                <option value="{{ $code }}">{{ $code }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-muted">{{ $offer->prices_include_vat ? __('offers.builder.basis_gross') : __('offers.builder.basis_net') }}</p>
                    </div>
                    <div>
                        <label for="valid_until" class="form-label">{{ __('offers.fields.valid_until') }}</label>
                        <input wire:model="valid_until" id="valid_until" type="date" class="form-input">
                        @error('valid_until') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="payment_terms_days" class="form-label">{{ __('offers.fields.payment_terms_days') }}</label>
                        <input wire:model="payment_terms_days" id="payment_terms_days" type="number" min="0" max="365" class="form-input">
                        @error('payment_terms_days') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="document_locale" class="form-label">{{ __('offers.fields.document_locale') }}</label>
                        <select wire:model="document_locale" id="document_locale" class="form-input">
                            <option value="">{{ __('offers.builder.customer_language') }}</option>
                            @foreach ($locales as $locale)
                                <option value="{{ $locale->value }}">{{ $locale->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    @foreach (['payment_terms_text', 'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes'] as $field)
                        <div class="sm:col-span-2 lg:col-span-3" wire:key="h-{{ $field }}">
                            <label for="{{ $field }}" class="form-label">{{ __('offers.fields.'.$field) }}</label>
                            <textarea wire:model="{{ $field }}" id="{{ $field }}" rows="2" class="form-input"></textarea>
                            @error($field) <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div class="sm:col-span-2 lg:col-span-3">
                        <label for="internal_notes" class="form-label flex items-center gap-2"><x-icon name="lock" class="size-4" /> {{ __('offers.fields.internal_notes') }}</label>
                        <textarea wire:model="internal_notes" id="internal_notes" rows="2" class="form-input" aria-describedby="internal-notes-help"></textarea>
                        <p id="internal-notes-help" class="mt-1 text-xs text-muted">{{ __('offers.builder.internal_hint') }}</p>
                    </div>
                    <div class="flex justify-end sm:col-span-2 lg:col-span-3">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('offers.builder.save_details') }}</button>
                    </div>
                </form>
            </section>
        @elseif ($tab === 'items')
            <div class="space-y-6" data-offer-items>
                @if ($this->headerIsDirty())
                    <p class="flex items-start gap-2 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" role="status" data-header-unsaved>
                        <x-icon name="warning" class="size-4 shrink-0" /> {{ __('common.document_tabs.header_unsaved') }}
                    </p>
                @endif

                {{-- Catalog search --}}
                <section class="card" aria-labelledby="catalog-heading">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                        <h3 id="catalog-heading" class="font-semibold text-ink">
                            @if ($this->targetGroupId)
                                {{ __('offers.builder.adding_to_group', ['group' => $offer->groups->firstWhere('id', $this->targetGroupId)?->title]) }}
                            @else
                                {{ __('offers.builder.adding_fixed') }}
                            @endif
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            @if ($this->targetGroupId)
                                <button type="button" wire:click="targetGroup(null)" class="btn btn-secondary px-3 py-1.5">{{ __('offers.builder.add_fixed_instead') }}</button>
                            @endif
                            <button type="button" wire:click="addGroup('alternative')" class="btn btn-secondary px-3 py-1.5" data-add-alternative><x-icon name="plus" class="size-4" /> {{ __('offers.builder.add_alternative_group') }}</button>
                            <button type="button" wire:click="addGroup('optional')" class="btn btn-secondary px-3 py-1.5" data-add-optional><x-icon name="plus" class="size-4" /> {{ __('offers.builder.add_optional_group') }}</button>
                        </div>
                    </div>
                    <livewire:offers.item-picker :offer-id="$offer->id" :target-group-id="$this->targetGroupId" :key="'item-picker-'.$offer->id" />
                </section>
                {{-- Blocks --}}
                <section class="space-y-4" aria-label="{{ __('offers.builder.items') }}">
                    @forelse ($this->blocks as $block)
                        @if ($block['type'] === 'line')
                            @include('livewire.offers.partials.builder-line', ['line' => $block['line'], 'number' => $block['number'], 'label' => (string) $block['number'], 'isFirst' => $loop->first, 'isLast' => $loop->last, 'inGroup' => false, 'group' => null])
                        @else
                            @php
                                $group = $block['group'];
                            @endphp
                            @php
                                $isAlternative = $group->kind === \App\Enums\OptionGroupKind::Alternative;
                            @endphp
                            <article wire:key="group-{{ $group->id }}" @class(['card p-4', 'border-4 border-double border-ink' => $isAlternative, 'border-2 border-line-strong' => ! $isAlternative, 'ring-2 ring-brand-600' => $this->targetGroupId === $group->id]) data-builder-group="{{ $group->kind->value }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <p class="text-xs font-bold tracking-wide text-ink uppercase">
                                        {{ $block['number'] }}.
                                        {{ $isAlternative ? __('offers.builder.alternative_heading', ['min' => $group->min_select, 'max' => $group->max_select]) : __('offers.builder.optional_heading') }}
                                    </p>
                                    <div class="flex flex-wrap gap-1">
                                        <button type="button" wire:click="moveBlock('group', {{ $group->id }}, -1)" class="btn btn-secondary px-2 py-1" @disabled($loop->first) aria-label="{{ __('offers.builder.move_up') }}">&uarr;</button>
                                        <button type="button" wire:click="moveBlock('group', {{ $group->id }}, 1)" class="btn btn-secondary px-2 py-1" @disabled($loop->last) aria-label="{{ __('offers.builder.move_down') }}">&darr;</button>
                                        <button type="button" wire:click="targetGroup({{ $group->id }})" class="btn btn-secondary px-3 py-1" data-add-option>{{ __('offers.builder.add_option') }}</button>
                                        @if ($block['lines']->count() === 1)
                                            <button type="button" wire:click="ungroup({{ $group->id }})" class="btn btn-secondary px-3 py-1">{{ __('offers.builder.ungroup') }}</button>
                                        @endif
                                        <button type="button" wire:click="removeGroup({{ $group->id }})" wire:confirm="{{ __('offers.builder.remove_group_confirm') }}" class="btn btn-danger-outline px-3 py-1">{{ __('offers.builder.remove_group') }}</button>
                                    </div>
                                </div>
                                <form wire:submit="saveGroup({{ $group->id }})" class="mt-3 grid gap-3 sm:grid-cols-[2fr_5rem_5rem_auto] sm:items-end" novalidate>
                                    <div>
                                        <label for="group-title-{{ $group->id }}" class="form-label">{{ __('offers.builder.group_title') }}</label>
                                        <input wire:model="groupInputs.{{ $group->id }}.title" id="group-title-{{ $group->id }}" type="text" maxlength="255" class="form-input">
                                    </div>
                                    <div>
                                        <label for="group-min-{{ $group->id }}" class="form-label">{{ __('offers.builder.min_select') }}</label>
                                        <input wire:model="groupInputs.{{ $group->id }}.min_select" id="group-min-{{ $group->id }}" type="number" min="0" max="6" class="form-input">
                                    </div>
                                    <div>
                                        <label for="group-max-{{ $group->id }}" class="form-label">{{ __('offers.builder.max_select') }}</label>
                                        <input wire:model="groupInputs.{{ $group->id }}.max_select" id="group-max-{{ $group->id }}" type="number" min="1" max="6" class="form-input">
                                    </div>
                                    <button type="submit" class="btn btn-secondary">{{ __('offers.builder.save_group') }}</button>
                                    <div class="sm:col-span-4">
                                        <label for="group-description-{{ $group->id }}" class="sr-only">{{ __('offers.builder.group_description') }}</label>
                                        <input wire:model="groupInputs.{{ $group->id }}.description" id="group-description-{{ $group->id }}" type="text" class="form-input" placeholder="{{ __('offers.builder.group_description') }}">
                                    </div>
                                    @error('groupInputs.'.$group->id.'.min_select') <p class="form-error sm:col-span-4">{{ $message }}</p> @enderror
                                </form>
                                <div class="mt-3 space-y-3">
                                    @forelse ($block['lines'] as $option)
                                        @include('livewire.offers.partials.builder-line', ['line' => $option, 'number' => $block['number'], 'label' => $block['number'].$option->option_label, 'isFirst' => $loop->first, 'isLast' => $loop->last, 'inGroup' => true, 'group' => $group])
                                    @empty
                                        <p class="text-sm text-muted">{{ __('offers.builder.group_empty') }}</p>
                                    @endforelse
                                </div>
                            </article>
                        @endif
                    @empty
                        <x-empty-state :title="__('offers.builder.empty')" :description="__('offers.builder.empty_hint')" icon="offers" class="card" />
                    @endforelse
                </section>
                <div class="grid gap-4 lg:grid-cols-2">
                    <section class="card p-5" data-totals-panel>
                        <h3 class="font-semibold text-ink">{{ __('offers.builder.totals') }}</h3>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('offers.totals.fixed') }}</dt><dd class="font-medium tabular-nums text-ink" data-fixed-total>{{ $money($offer->fixed_gross_minor) }}</dd></div>
                            @foreach ($this->blocks->where('type', 'group') as $block)
                                <div class="flex justify-between gap-3 text-xs" wire:key="sum-{{ $block['group']->id }}">
                                    <dt class="text-muted">{{ $block['number'] }}. {{ $block['group']->title }}</dt>
                                    <dd class="text-ink">{{ trans_choice('offers.totals.group_summary', $block['lines']->count(), ['count' => $block['lines']->count(), 'min' => $block['group']->min_select, 'max' => $block['group']->max_select]) }}</dd>
                                </div>
                            @endforeach
                            @if ($offer->range_min_gross_minor !== $offer->range_max_gross_minor)
                                <div class="flex justify-between gap-3 border-t border-line pt-2"><dt class="text-muted">{{ __('offers.totals.range') }}</dt><dd class="text-right font-medium tabular-nums text-ink" data-range>{{ $money($offer->range_min_gross_minor) }} – {{ $money($offer->range_max_gross_minor) }}</dd></div>
                            @endif
                            @if ($offer->groups->isNotEmpty())
                                <div class="flex justify-between gap-3"><dt class="text-muted">{{ __('offers.totals.recommended') }}</dt><dd class="tabular-nums text-ink" data-recommended>{{ $money($offer->recommended_gross_minor) }}</dd></div>
                            @else
                                <div class="flex justify-between gap-3 border-t border-line pt-2 font-semibold"><dt class="text-ink">{{ __('offers.totals.total') }}</dt><dd class="tabular-nums text-ink">{{ $money($offer->selected_gross_minor) }}</dd></div>
                            @endif
                            <div class="flex justify-between gap-3 border-t border-line pt-2"><dt class="text-muted">{{ __('offers.totals.max_discount') }}</dt><dd class="tabular-nums text-ink">{{ $percent($this->approval['max']) }}</dd></div>
                            <div class="flex justify-between gap-3" data-margin><dt class="flex items-center gap-1 text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('offers.totals.margin') }}</dt><dd class="tabular-nums text-ink">{{ $this->approval['margin'] === null ? __('offers.totals.margin_unknown') : $money($this->approval['margin']) }}</dd></div>
                        </dl>
                        @if ($this->approval['below_cost'])
                            <p class="mt-3 flex items-start gap-2 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-xs text-brand-800" data-below-cost>
                                <x-icon name="warning" class="size-4 shrink-0" /> {{ __('offers.builder.below_cost') }}
                            </p>
                        @endif
                        @if ($this->approval['required'])
                            <p class="mt-3 flex items-start gap-2 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-xs text-brand-800" data-approval-required>
                                <x-icon name="warning" class="size-4 shrink-0" /> {{ __('offers.builder.approval_required', ['limit' => $percent($this->approval['owner_limit'])]) }}
                            </p>
                        @else
                            <p class="mt-3 text-xs text-muted">{{ __('offers.builder.within_limit', ['limit' => $percent($this->approval['owner_limit'])]) }}</p>
                        @endif
                    </section>
                    <section class="card p-5" aria-labelledby="checks-heading" data-checks>
                        <h3 id="checks-heading" class="font-semibold text-ink">{{ __('offers.builder.checks') }}</h3>
                        @if ($this->issues === [])
                            <p class="mt-2 flex items-center gap-2 text-sm text-ink"><x-icon name="check" class="size-4" /> {{ __('offers.builder.no_issues') }}</p>
                        @else
                            <ul class="mt-2 space-y-2 text-sm">
                                @foreach ($this->issues as $issue)
                                    <li wire:key="issue-{{ $loop->index }}" class="flex items-start gap-2" data-issue="{{ $issue['code'] }}">
                                        <x-badge :tone="$issue['level'] === 'blocker' ? 'red' : 'outline'">{{ __('offers.builder.level_'.$issue['level']) }}</x-badge>
                                        <span class="text-ink">{{ __('offers.validation.'.$issue['key'], $issue['params']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>
            </div>
        @elseif ($tab === 'preview')
            <section class="card space-y-4 p-5" aria-label="{{ __('offers.builder.tab_preview') }}">
                <p class="rounded-md border border-line-strong bg-neutral-50 px-3 py-2 text-xs text-ink" role="note">{{ __('offers.builder.preview_banner') }}</p>
                <x-offers.customer-view :offer="$offer" :blocks="$this->blocks" :selected="$this->previewSelectedIds()" :totals="$this->previewTotals" interactive />
            </section>
        @else
            <livewire:offers.history :offer-id="$offer->id" :key="'offer-history-'.$offer->id" />
        @endif
    </x-tabs>
</div>
