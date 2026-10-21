<div class="space-y-6">
    @php
        $lead = $this->lead;
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
    @endphp

    <a href="{{ route('leads.index') }}" wire:navigate class="link text-sm">&larr; {{ __('leads.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $lead->number }}</p>
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">
                    @if ($lead->customer)
                        @can('view', $lead->customer)
                            <a href="{{ route('customers.show', $lead->customer) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $lead->customer->name }}</a>
                        @else
                            {{ $lead->customer->name }}
                        @endcan
                    @else
                        {{ $lead->company_name }}
                    @endif
                </h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-leads.status-badge :status="$lead->status" />
                    @if ($lead->isStale())
                        <x-badge tone="danger-outline" icon="clock" data-stale>{{ __('leads.stale') }}</x-badge>
                    @endif
                    @if ($lead->merged_into_id !== null)
                        <a href="{{ route('leads.show', $lead->mergedInto) }}" data-merged-into><x-badge tone="outline">{{ __('leads.merged_into', ['number' => $lead->mergedInto->number]) }}</x-badge></a>
                    @endif
                    @if ($lead->anonymised_at !== null)
                        <x-badge tone="outline" icon="lock" data-anonymised>{{ __('leads.anonymised_badge') }}</x-badge>
                    @endif
                    @if ($lead->customer_id === null)
                        <x-badge tone="outline">{{ __('leads.not_a_customer_yet') }}</x-badge>
                    @endif
                    @if ($lead->status === \App\Enums\LeadStatus::Converted)
                        <x-badge tone="outline" icon="lock" data-read-only>{{ __('leads.conversion.read_only') }}</x-badge>
                    @endif
                    @if ($telegramChat)
                        <x-badge tone="outline" data-telegram-group-badge>{{ __('telegram.groups.badge') }}</x-badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2" data-lead-actions>
                @can('update', $lead)
                    <a href="{{ route('leads.edit', $lead) }}" wire:navigate class="btn btn-secondary">{{ __('common.edit') }}</a>
                @endcan
                @can('convertToCustomer', $lead)
                    <button type="button" wire:click="openPanel('convert')" class="btn btn-primary" data-action="convert-customer">{{ __('leads.actions.convert_customer') }}</button>
                @endcan
                @can('markContacted', $lead)
                    <button type="button" wire:click="markContacted" class="btn btn-secondary" data-action="contacted">{{ __('leads.actions.contacted') }}</button>
                @endcan
                @can('qualify', $lead)
                    <button type="button" wire:click="qualify" class="btn btn-primary" data-action="qualify">{{ __('leads.actions.qualify') }}</button>
                @endcan
                @can('assign', $lead)
                    <button type="button" wire:click="openPanel('assign')" class="btn btn-secondary" data-action="assign">{{ __('leads.actions.assign') }}</button>
                @endcan
                @can('merge', $lead)
                    <button type="button" wire:click="openPanel('merge')" class="btn btn-secondary" data-action="merge">{{ __('leads.actions.merge') }}</button>
                @endcan
                @can('close', $lead)
                    <button type="button" wire:click="openPanel('close')" class="btn btn-danger-outline" data-action="close">{{ __('leads.actions.close') }}</button>
                @endcan
                @can('reopen', $lead)
                    <button type="button" wire:click="openPanel('reopen')" class="btn btn-secondary" data-action="reopen">{{ __('leads.actions.reopen') }}</button>
                @endcan
            </div>
        </div>

        @if ($panel === 'convert')
            @can('convertToCustomer', $lead)
                <livewire:leads.convert-to-customer :lead-id="$lead->id" :key="'convert-'.$lead->id" />
            @endcan
        @elseif ($panel === 'close')
            <form wire:submit="close" class="mt-5 grid gap-4 border-t border-line pt-5 sm:grid-cols-3" data-panel="close" novalidate>
                <fieldset class="sm:col-span-3">
                    <legend class="form-label">{{ __('leads.fields.close_as') }}</legend>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="closeStatus" type="radio" value="lost" class="accent-brand-600"> {{ \App\Enums\LeadStatus::Lost->label() }}</label>
                        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="closeStatus" type="radio" value="disqualified" class="accent-brand-600"> {{ \App\Enums\LeadStatus::Disqualified->label() }}</label>
                    </div>
                </fieldset>
                <div>
                    <label for="close-reason" class="form-label">{{ __('leads.fields.close_reason') }}</label>
                    <select wire:model="closeReason" id="close-reason" class="form-input">
                        <option value="">—</option>
                        @foreach ($closeReasons as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('closeReason') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="close-note" class="form-label">{{ __('leads.fields.close_note') }}</label>
                    <input wire:model="closeNote" id="close-note" type="text" class="form-input" maxlength="2000">
                    @error('closeNote') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 sm:col-span-3">
                    <button type="button" wire:click="openPanel('')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-danger-outline">{{ __('leads.actions.close') }}</button>
                </div>
            </form>
        @elseif ($panel === 'merge')
            @can('merge', $lead)
                <form wire:submit="merge" class="mt-5 space-y-3 border-t border-line pt-5" data-panel="merge" novalidate>
                    <p class="text-sm text-muted">{{ __('leads.merge.hint') }}</p>
                    @if ($this->mergeCandidates->isNotEmpty())
                        <div>
                            <p class="form-label">{{ __('leads.merge.suggest') }}</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->mergeCandidates as $candidate)
                                    <button type="button" wire:click="$set('mergeNumber', '{{ $candidate->number }}')" class="btn btn-secondary" data-merge-candidate="{{ $candidate->number }}">{{ $candidate->number }} · {{ $candidate->company_name }}</button>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <div class="max-w-xs">
                        <label for="merge-number" class="form-label">{{ __('leads.fields.merge_target') }}</label>
                        <input wire:model="mergeNumber" id="merge-number" type="text" class="form-input" maxlength="30" placeholder="LD-2026-00001">
                        @error('mergeNumber') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="openPanel('')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                        <button type="submit" class="btn btn-primary">{{ __('leads.merge.submit') }}</button>
                    </div>
                </form>
            @endcan
        @elseif ($panel === 'reopen')
            <form wire:submit="reopen" class="mt-5 space-y-3 border-t border-line pt-5" data-panel="reopen" novalidate>
                <label for="reopen-comment" class="form-label">{{ __('leads.fields.reopen_comment') }}</label>
                <input wire:model="reopenComment" id="reopen-comment" type="text" class="form-input" maxlength="2000">
                @error('reopenComment') <p class="form-error">{{ $message }}</p> @enderror
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="openPanel('')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('leads.actions.reopen') }}</button>
                </div>
            </form>
        @elseif ($panel === 'assign')
            <form wire:submit="assign" class="mt-5 flex flex-wrap items-end gap-3 border-t border-line pt-5" data-panel="assign" novalidate>
                <div class="min-w-64">
                    <label for="new-owner" class="form-label">{{ __('leads.fields.owner') }}</label>
                    <select wire:model="newOwnerId" id="new-owner" class="form-input">
                        @foreach ($this->assignableOwners as $candidate)
                            <option value="{{ $candidate->id }}" wire:key="owner-{{ $candidate->id }}">{{ $candidate->name }}</option>
                        @endforeach
                    </select>
                    @error('newOwnerId') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <button type="button" wire:click="openPanel('')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('leads.actions.assign') }}</button>
            </form>
        @endif
    </header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card p-6" aria-labelledby="lead-details">
                <h3 id="lead-details" class="mb-4 font-semibold text-ink">{{ __('leads.sections.details') }}</h3>
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.contact') }}</dt><dd class="text-ink">{{ $lead->contactLabel() ?? '—' }}@if ($lead->contact_position) · {{ $lead->contact_position }}@endif</dd></div>
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.reach') }}</dt><dd class="text-ink">{{ collect([$lead->contact?->email ?? $lead->contact_email, $lead->contact?->phone ?? $lead->contact_phone])->filter()->join(' · ') ?: '—' }}</dd></div>
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.summary.score') }}</dt><dd class="text-ink tabular-nums" data-lead-score="{{ $lead->score }}">{{ $lead->score }}/100 · {{ $lead->priority?->label() }}</dd></div>
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.source') }}</dt><dd class="text-ink">{{ $lead->source->label() }}@if ($lead->campaign) · {{ $lead->campaign }}@endif</dd></div>
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.owner') }}</dt><dd class="text-ink">{{ $lead->owner?->name ?? __('leads.unassigned') }}</dd></div>
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.est_value') }}</dt><dd class="text-ink">{{ $lead->estimatedValue()?->format() ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.expected_close_date') }}</dt><dd class="text-ink">{{ $lead->expected_close_date?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
                    @if ($lead->customer_id === null)
                        <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.tax_id') }}</dt><dd class="font-mono text-ink">{{ $lead->tax_id ?? '—' }}</dd></div>
                        <div><dt class="text-xs text-muted uppercase">{{ __('leads.fields.industry') }}</dt><dd class="text-ink">{{ $lead->industry ?? '—' }}</dd></div>
                    @endif
                    @if ($lead->close_reason)
                        <div class="sm:col-span-2"><dt class="text-xs text-muted uppercase">{{ __('leads.fields.close_reason') }}</dt><dd class="text-ink">{{ $lead->close_reason->label() }}@if ($lead->close_note) · {{ $lead->close_note }}@endif</dd></div>
                    @endif
                    <div class="sm:col-span-2"><dt class="text-xs text-muted uppercase">{{ __('leads.fields.need') }}</dt><dd class="whitespace-pre-line text-ink">{{ $lead->need ?? '—' }}</dd></div>
                </dl>
            </section>

            <section class="card" aria-labelledby="lead-items">
                <h3 id="lead-items" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('leads.fields.items') }}</h3>
                @if ($lead->items->isEmpty())
                    <p class="px-6 py-4 text-sm text-muted">{{ __('leads.no_items') }}</p>
                @else
                    <ul class="divide-y divide-line text-sm" data-lead-items>
                        @foreach ($lead->items as $interest)
                            <li class="flex justify-between gap-3 px-6 py-3" wire:key="interest-{{ $interest->id }}">
                                <span class="text-ink">{{ $interest->item?->name }} <span class="font-mono text-xs text-muted">{{ $interest->item?->sku }}</span></span>
                                <span class="whitespace-nowrap text-ink">{{ \App\Support\Quantity::trimmed((string) $interest->quantity) }} {{ $interest->item?->unit?->code }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card" aria-labelledby="lead-timeline">
                <h3 id="lead-timeline" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('leads.sections.timeline') }}</h3>
                @can('logActivity', $lead)
                    <form wire:submit="logActivity" class="flex flex-col gap-3 border-b border-line px-6 py-4 sm:flex-row sm:items-start" data-activity-form novalidate>
                        <label for="activity-type" class="sr-only">{{ __('leads.fields.activity_type') }}</label>
                        <select wire:model="activityType" id="activity-type" class="form-input sm:w-40">
                            @foreach ($activityTypes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="flex-1">
                            <label for="activity-text" class="sr-only">{{ __('leads.fields.activity_text') }}</label>
                            <textarea wire:model="activityText" id="activity-text" rows="2" class="form-input" maxlength="{{ config('leads.activity_max_length') }}" placeholder="{{ __('leads.activity_placeholder') }}"></textarea>
                            @error('activityText') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <button type="submit" class="btn btn-secondary">{{ __('leads.actions.log_activity') }}</button>
                    </form>
                @endcan
                <ol class="divide-y divide-line" data-timeline>
                    @forelse ($this->timeline as $entry)
                        @php($model = $entry['model'])
                        <li class="px-6 py-3 text-sm" wire:key="tl-{{ $entry['kind'] }}-{{ $model->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-ink">
                                    @if ($entry['kind'] === 'transition')
                                        <span class="font-semibold">{{ $model->from_status ? __('leads.transition', ['from' => \App\Enums\LeadStatus::from($model->from_status)->label(), 'to' => \App\Enums\LeadStatus::from($model->to_status)->label()]) : __('leads.created_as', ['status' => \App\Enums\LeadStatus::from($model->to_status)->label()]) }}</span>
                                        <span class="text-muted">· {{ $model->user?->name ?? __('activity.system') }}</span>
                                    @elseif ($entry['kind'] === 'activity')
                                        <span class="font-semibold">{{ \App\Enums\LeadActivityType::tryFrom((string) ($model->properties['type'] ?? ''))?->label() ?? $model->eventLabel() }}</span>
                                        <span class="text-muted">· {{ $model->causer?->name ?? ($model->properties['from'] ?? __('activity.system')) }}</span>
                                    @else
                                        <span class="font-semibold">{{ $model->eventLabel() }}</span>
                                        <span class="text-muted">· {{ $model->causer?->name ?? __('activity.system') }}</span>
                                    @endif
                                </p>
                                <time datetime="{{ $entry['at']?->toIso8601String() }}" class="text-xs text-muted">{{ $entry['at']?->translatedFormat($dateTimeFormat) }}</time>
                            </div>
                            @if ($entry['kind'] === 'transition' && $model->reasonLabel())
                                <p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $model->reasonLabel() }}</p>
                            @elseif ($entry['kind'] === 'activity')
                                <p class="mt-1 whitespace-pre-line text-ink">{{ $model->properties['text'] ?? '' }}</p>
                            @endif
                        </li>
                    @empty
                        <li><x-empty-state :title="__('activity.empty')" icon="clock" /></li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="space-y-6">
            @if ($telegramChat)
                @can('view', $telegramChat)
                    <livewire:telegram.chat-panel :chat-id="$telegramChat->id" :key="'tg-panel-'.$telegramChat->id" />
                @endcan
            @endif
            @if ($lead->status->isOpen())
                <section class="card p-6" aria-labelledby="lead-gate" data-gate>
                    <h3 id="lead-gate" class="font-semibold text-ink">{{ __('leads.sections.qualification') }}</h3>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach (['customer', 'contact', 'need', ...\App\Models\Lead::BANT] as $check)
                            @php($missing = in_array($check, $gaps, true) || ($check === 'customer' && in_array('customer_unavailable', $gaps, true)))
                            <li class="flex items-center gap-2" wire:key="gate-{{ $check }}">
                                <x-icon :name="$missing ? 'close' : 'check'" class="size-4 {{ $missing ? 'text-brand-700' : 'text-ink' }}" />
                                <span @class(['text-ink' => ! $missing, 'text-brand-800' => $missing])>{{ __('leads.gate.'.$check) }}</span>
                                <span class="sr-only">{{ $missing ? __('leads.gate_missing') : __('leads.gate_done') }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($gaps === [] && $lead->status !== \App\Enums\LeadStatus::Qualified)
                        <p class="mt-3 text-xs text-muted">{{ __('leads.gate_ready') }}</p>
                    @endif
                </section>
            @endif

            <section class="card p-6" aria-labelledby="lead-follow-ups" data-follow-ups>
                <h3 id="lead-follow-ups" class="font-semibold text-ink">{{ __('leads.follow_up.title') }}</h3>
                <ul class="mt-3 space-y-2 text-sm">
                    @forelse ($lead->followUps as $task)
                        <li class="flex items-start justify-between gap-2" wire:key="fu-{{ $task->id }}">
                            <span @class(['text-muted line-through' => $task->completed_at, 'text-ink' => ! $task->completed_at])>
                                <span class="tabular-nums">{{ $task->due_at->translatedFormat($dateTimeFormat) }}</span>
                                @if ($task->isOverdue()) <x-badge tone="danger-outline" icon="clock">{{ __('leads.follow_up_overdue') }}</x-badge> @endif
                                @if ($task->note) <span class="block text-xs text-muted">{{ $task->note }}</span> @endif
                                <span class="block text-xs text-muted">{{ $task->assignee?->name ?? __('leads.unassigned') }}</span>
                            </span>
                            @if (! $task->completed_at)
                                @can('logActivity', $lead)
                                    <button type="button" wire:click="completeFollowUp({{ $task->id }})" class="btn btn-secondary btn-sm" data-action="complete-follow-up">{{ __('leads.actions.complete_follow_up') }}</button>
                                @endcan
                            @endif
                        </li>
                    @empty
                        <li class="text-muted">{{ __('leads.no_follow_ups') }}</li>
                    @endforelse
                </ul>
                @can('logActivity', $lead)
                    <form wire:submit="addFollowUp" class="mt-4 space-y-2 border-t border-line pt-4" novalidate>
                        <label for="fu-due" class="form-label">{{ __('leads.fields.follow_up_due') }}</label>
                        <input wire:model="followUpDue" id="fu-due" type="datetime-local" class="form-input">
                        @error('followUpDue') <p class="form-error">{{ $message }}</p> @enderror
                        <label for="fu-note" class="sr-only">{{ __('leads.fields.follow_up_note') }}</label>
                        <input wire:model="followUpNote" id="fu-note" type="text" maxlength="500" class="form-input" placeholder="{{ __('leads.fields.follow_up_note') }}">
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('leads.actions.add_follow_up') }}</button>
                    </form>
                @endcan
            </section>

            <section class="card p-6" aria-labelledby="lead-offers">
                <h3 id="lead-offers" class="font-semibold text-ink">{{ __('leads.sections.offers') }}</h3>
                @if ($lead->offers->isEmpty())
                    <p class="mt-2 text-sm text-muted">{{ __('leads.no_offers') }}</p>
                @else
                    <ul class="mt-3 space-y-2 text-sm" data-lead-offers>
                        @foreach ($lead->offers as $offer)
                            <li class="flex items-center justify-between gap-2" wire:key="lead-offer-{{ $offer->id }}">
                                @can('view', $offer)
                                    <a href="{{ route('offers.show', $offer) }}" wire:navigate class="link font-mono">{{ $offer->displayNumber() }}</a>
                                @else
                                    <span class="font-mono text-ink">{{ $offer->displayNumber() }}</span>
                                @endcan
                                <x-offers.status-badge :status="$offer->status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card p-6 text-sm" aria-labelledby="lead-meta">
                <h3 id="lead-meta" class="sr-only">{{ __('leads.sections.meta') }}</h3>
                <dl class="space-y-2">
                    <div class="flex justify-between gap-2"><dt class="text-muted">{{ __('leads.fields.created') }}</dt><dd class="text-right text-ink">{{ $lead->created_at?->translatedFormat($dateTimeFormat) }} · {{ $lead->creator?->name ?? '—' }}</dd></div>
                    @if ($lead->qualified_at)<div class="flex justify-between gap-2"><dt class="text-muted">{{ __('leads.fields.qualified_at') }}</dt><dd class="text-ink">{{ $lead->qualified_at->translatedFormat($dateTimeFormat) }}</dd></div>@endif
                    @if ($lead->converted_at)<div class="flex justify-between gap-2"><dt class="text-muted">{{ __('leads.fields.converted_at') }}</dt><dd class="text-right text-ink" data-converted-by>{{ $lead->converted_at->translatedFormat($dateTimeFormat) }}@if ($lead->convertedBy) · {{ $lead->convertedBy->name }}@endif</dd></div>@endif
                    @if ($lead->closed_at)<div class="flex justify-between gap-2"><dt class="text-muted">{{ __('leads.fields.closed_at') }}</dt><dd class="text-ink">{{ $lead->closed_at->translatedFormat($dateTimeFormat) }}</dd></div>@endif
                </dl>
            </section>
        </aside>
    </div>
</div>
