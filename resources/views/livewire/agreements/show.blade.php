<div class="space-y-6">
    @php
        $agreement = $this->agreement;
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $tabs = ['document' => __('agreements.show.tab_document'), 'dispatches' => __('agreements.show.tab_dispatches'), 'timeline' => __('agreements.show.tab_timeline')];
        $document = $this->document;
    @endphp

    <a href="{{ route('agreements.index') }}" wire:navigate class="link text-sm">&larr; {{ __('agreements.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $agreement->number }} · {{ $agreement->kind->label() }} · {{ __('agreements.show.version', ['version' => $agreement->version]) }}</p>
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">
                    @can('view', $agreement->customer)
                        <a href="{{ route('customers.show', $agreement->customer) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $agreement->customer?->name }}</a>
                    @else
                        {{ $agreement->customer?->name }}
                    @endcan
                </h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-agreements.status-badge :status="$agreement->status" />
                    @if ($agreement->is_non_standard && $agreement->status->isEditable())
                        <x-badge tone="danger-outline" icon="warning" data-non-standard>{{ __('agreements.show.needs_review') }}</x-badge>
                    @endif
                    @if ($agreement->approved_at && $agreement->status === \App\Enums\AgreementStatus::InReview)
                        <x-badge tone="dark" icon="check">{{ __('agreements.show.approved') }}</x-badge>
                    @endif
                    @if ($agreement->customer_signed_uploaded_at && $agreement->status === \App\Enums\AgreementStatus::SentForSignature)
                        <x-badge tone="red" icon="clock" data-scan-uploaded>{{ __('agreements.scan_uploaded') }}</x-badge>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2" data-agreement-actions>
                @can('submit', $agreement)
                    @if ($agreement->is_non_standard)
                        <button type="button" wire:click="submit" class="btn btn-primary" data-action="submit">{{ __('agreements.actions.submit') }}</button>
                    @endif
                @endcan
                @can('approve', $agreement)
                    <button type="button" wire:click="openPanel('approve')" class="btn btn-primary" data-action="approve">{{ __('agreements.actions.approve') }}</button>
                    <button type="button" wire:click="openPanel('return')" class="btn btn-secondary" data-action="return">{{ __('agreements.actions.return') }}</button>
                @endcan
                @can('send', $agreement)
                    @if (! ($agreement->status === \App\Enums\AgreementStatus::Draft && $agreement->is_non_standard))
                        <button type="button" wire:click="openPanel('send')" class="btn btn-primary" data-action="send">{{ $agreement->status === \App\Enums\AgreementStatus::SentForSignature ? __('agreements.actions.resend') : __('agreements.actions.send') }}</button>
                    @endif
                @endcan
                @can('uploadScan', $agreement)
                    <button type="button" wire:click="openPanel('upload')" class="btn btn-secondary" data-action="upload">{{ __('agreements.actions.upload_scan') }}</button>
                @endcan
                @can('confirmSigned', $agreement)
                    <button type="button" wire:click="openPanel('signed')" class="btn btn-primary" data-action="signed">{{ __('agreements.actions.confirm_signed') }}</button>
                @endcan
                @can('renew', $agreement)
                    <button type="button" wire:click="renew" class="btn btn-secondary" data-action="renew">{{ __('agreements.actions.renew') }}</button>
                @endcan
                @can('addAddendum', $agreement)
                    <button type="button" wire:click="addAddendum" class="btn btn-secondary" data-action="addendum">{{ __('agreements.actions.addendum') }}</button>
                @endcan
                @can('print', $agreement)
                    <a href="{{ route('agreements.print', ['agreement' => $agreement, 'version' => $this->version ?: null]) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('agreements.actions.print') }}</a>
                @endcan
                @can('reissue', $agreement)
                    <button type="button" wire:click="openPanel('reissue')" class="btn btn-secondary" data-action="reissue">{{ __('agreements.actions.reissue') }}</button>
                @endcan
                @can('terminate', $agreement)
                    <button type="button" wire:click="openPanel('terminate')" class="btn btn-danger-outline" data-action="terminate">{{ __('agreements.actions.terminate') }}</button>
                @endcan
                @can('cancel', $agreement)
                    <button type="button" wire:click="openPanel('cancel')" class="btn btn-danger-outline" data-action="cancel">{{ __('agreements.actions.cancel') }}</button>
                @endcan
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            @if ($agreement->order)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.order') }}</dt><dd class="mt-1">@can('view', $agreement->order)<a href="{{ route('orders.show', $agreement->order) }}" wire:navigate class="link font-mono">{{ $agreement->order->number }}</a>@else<span class="font-mono">{{ $agreement->order->number }}</span>@endcan</dd></div>
            @endif
            @if ($agreement->parent)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.parent') }}</dt><dd class="mt-1"><a href="{{ route('agreements.show', $agreement->parent) }}" wire:navigate class="link font-mono">{{ $agreement->parent->number }}</a></dd></div>
            @endif
            @if ($agreement->renewalOf)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.renewal_of') }}</dt><dd class="mt-1"><a href="{{ route('agreements.show', $agreement->renewalOf) }}" wire:navigate class="link font-mono">{{ $agreement->renewalOf->number }}</a></dd></div>
            @endif
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.value') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $agreement->value_minor !== null ? \App\Support\Money::of($agreement->value_minor, $agreement->currency)->format() : '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.term') }}</dt><dd class="mt-1 text-ink">{{ $agreement->starts_on?->translatedFormat($dateFormat) ?? '—' }} – {{ $agreement->ends_on?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.template') }}</dt><dd class="mt-1 text-ink">{{ $agreement->template?->name }} · v{{ $agreement->template_version }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.languages') }}</dt><dd class="mt-1 text-ink">{{ collect($agreement->printLocales())->map(fn ($l) => \App\Enums\Locale::from($l)->label())->implode(' + ') }}</dd></div>
            @if ($agreement->signed_on)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.signed_on') }}</dt><dd class="mt-1 text-ink">{{ $agreement->signed_on->translatedFormat($dateFormat) }} · {{ $agreement->customer_signatory_name }}</dd></div>
            @endif
            @if ($agreement->signedAttachment)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.scan') }}</dt><dd class="mt-1">@can('downloadScan', $agreement)<a href="{{ route('attachments.download', $agreement->signedAttachment) }}" class="link" data-scan-link>{{ $agreement->signedAttachment->original_name }}</a>@endcan</dd></div>
            @endif
            @if ($agreement->terminated_on)
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.fields.terminated_on') }}</dt><dd class="mt-1 text-ink">{{ $agreement->terminated_on->translatedFormat($dateFormat) }} · {{ __('agreements.termination_reasons.'.$agreement->termination_reason_code) }}: {{ $agreement->termination_reason }}</dd></div>
            @endif
            @if ($agreement->cancelled_at)
                <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('agreements.show.cancelled') }}</dt><dd class="mt-1 text-ink">{{ $agreement->cancelled_at->translatedFormat($dateTimeFormat) }} · {{ (new \App\Models\StatusTransition(['subject_type' => 'agreement', 'reason' => $agreement->cancel_reason]))->reasonLabel() }}</dd></div>
            @endif
        </dl>

        @if ($agreement->non_standard_reasons && $agreement->status->isEditable())
            <p class="mt-4 text-sm text-ink" data-non-standard-reasons>{{ __('agreements.show.non_standard', ['reasons' => collect($agreement->non_standard_reasons)->map(fn ($r) => __('agreements.non_standard.'.$r))->implode('; ')]) }}</p>
        @endif
        @if ($this->problems !== [])
            <ul class="mt-4 space-y-1 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-problems>
                @foreach ($this->problems as $problem)
                    <li wire:key="problem-{{ $loop->index }}">{{ __('agreements.validation.'.$problem['key'], $problem['params']) }}</li>
                @endforeach
            </ul>
        @endif
    </header>

    @if ($panel !== '')
        <section class="card border-2 border-brand-600 p-5" role="dialog" aria-labelledby="panel-title" data-panel="{{ $panel }}">
            <h3 id="panel-title" class="font-semibold text-ink">{{ __('agreements.panels.'.$panel) }}</h3>

            @if ($panel === 'send')
                <fieldset class="mt-4">
                    <legend class="form-label">{{ __('agreements.fields.channels') }}</legend>
                    @foreach ($allChannels as $channel)
                        @php($enabled = in_array($channel, $availableChannels, true))
                        <label @class(['flex items-center gap-2 py-1 text-sm', 'text-ink' => $enabled, 'text-muted' => ! $enabled]) wire:key="ch-{{ $channel->value }}">
                            <input type="checkbox" wire:model="channels" value="{{ $channel->value }}" class="size-4 accent-brand-600" @disabled(! $enabled)>
                            {{ $channel->label() }} @unless ($enabled) <span class="text-xs">({{ __('agreements.panels.channel_soon') }})</span> @endunless
                        </label>
                    @endforeach
                    @error('channels') <p class="form-error">{{ $message }}</p> @enderror
                </fieldset>
                <div class="mt-3">
                    <label for="p-recipient" class="form-label">{{ __('agreements.fields.recipient') }}</label>
                    <select wire:model="recipientContactId" id="p-recipient" class="form-input">
                        <option value="">—</option>
                        @foreach ($agreement->customer?->contacts ?? [] as $contact)
                            <option value="{{ $contact->id }}">{{ $contact->fullName() }}@if ($contact->is_signatory) · {{ __('agreements.signatory') }}@endif</option>
                        @endforeach
                    </select>
                </div>
                @if ($agreement->status !== \App\Enums\AgreementStatus::SentForSignature)
                    <p class="mt-3 text-xs text-muted">{{ __('agreements.panels.send_help') }}</p>
                @endif
            @endif

            @if ($panel === 'upload')
                <div class="mt-4">
                    <label for="p-scan" class="form-label">{{ __('agreements.fields.scan') }}</label>
                    <input wire:model="scan" id="p-scan" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="form-input">
                    <p class="mt-1 text-xs text-muted">{{ __('agreements.panels.scan_help', ['max' => intdiv((int) config('agreements.scan_max_kb'), 1024)]) }}</p>
                    @error('scan') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            @if ($panel === 'signed')
                @unless ($agreement->signed_attachment_id)
                    <p class="mt-3 text-sm text-brand-800">{{ __('agreements.errors.scan_required') }}</p>
                @endunless
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="s-date" class="form-label">{{ __('agreements.fields.signed_on') }}</label>
                        <input wire:model="signature.signed_on" id="s-date" type="date" class="form-input">
                        @error('signature.signed_on') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-our" class="form-label">{{ __('agreements.fields.our_signatory') }}</label>
                        <select wire:model="signature.our_signatory_id" id="s-our" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->signatories as $signatory)
                                <option value="{{ $signatory->id }}">{{ $signatory->full_name }} · {{ $signatory->position }}</option>
                            @endforeach
                        </select>
                        @error('signature.our_signatory_id') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-name" class="form-label">{{ __('agreements.fields.customer_signatory_name') }}</label>
                        <input wire:model="signature.customer_signatory_name" id="s-name" type="text" class="form-input">
                        @error('signature.customer_signatory_name') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="s-position" class="form-label">{{ __('agreements.fields.customer_signatory_position') }}</label>
                        <input wire:model="signature.customer_signatory_position" id="s-position" type="text" class="form-input">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="s-basis" class="form-label">{{ __('agreements.fields.customer_signatory_basis') }}</label>
                        <input wire:model="signature.customer_signatory_basis" id="s-basis" type="text" class="form-input">
                    </div>
                </div>
            @endif

            @if ($panel === 'terminate')
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="t-date" class="form-label">{{ __('agreements.fields.terminated_on') }}</label>
                        <input wire:model="termination.date" id="t-date" type="date" class="form-input">
                        <p class="mt-1 text-xs text-muted">{{ trans_choice('agreements.panels.notice_help', $agreement->notice_days, ['count' => $agreement->notice_days]) }}</p>
                        @error('termination.date') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="t-code" class="form-label">{{ __('agreements.fields.termination_reason_code') }}</label>
                        <select wire:model="termination.reason_code" id="t-code" class="form-input">
                            <option value="">—</option>
                            @foreach ($terminationReasons as $code)
                                <option value="{{ $code }}">{{ __('agreements.termination_reasons.'.$code) }}</option>
                            @endforeach
                        </select>
                        @error('termination.reason_code') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="t-reason" class="form-label">{{ __('agreements.fields.termination_reason') }}</label>
                        <textarea wire:model="termination.reason" id="t-reason" rows="2" class="form-input"></textarea>
                        @error('termination.reason') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            @if (in_array($panel, ['approve', 'return', 'reissue', 'cancel'], true))
                <div class="mt-4">
                    <label for="p-comment" class="form-label">{{ __('agreements.fields.'.['approve' => 'comment', 'return' => 'comment', 'reissue' => 'reissue_reason', 'cancel' => 'cancel_reason'][$panel]) }}</label>
                    <textarea wire:model="comment" id="p-comment" rows="2" class="form-input"></textarea>
                    @error('comment') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="closePanel" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                @php($submitAction = ['send' => 'send', 'approve' => 'approve', 'return' => 'returnToDraft', 'upload' => 'uploadScan', 'signed' => 'confirmSigned', 'reissue' => 'reissue', 'cancel' => 'cancel', 'terminate' => 'terminate'][$panel])
                <button type="button" wire:click="{{ $submitAction }}" class="btn btn-primary" wire:loading.attr="disabled" data-confirm-panel>{{ __('agreements.panels.confirm_'.$panel) }}</button>
            </div>
        </section>
    @endif

    @can('update', $agreement)
        <section class="card p-5" data-draft-editor>
            <h3 class="font-semibold text-ink">{{ __('agreements.editor.title') }}</h3>
            <p class="mt-1 text-xs text-muted">{{ __('agreements.editor.help') }}</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="f-secondary" class="form-label">{{ __('agreements.fields.secondary_locale') }}</label>
                    <select wire:model="form.secondary_locale" id="f-secondary" class="form-input">
                        <option value="">{{ __('agreements.editor.no_second_language') }}</option>
                        @foreach ($locales as $locale)
                            @continue($locale->value === $agreement->document_locale)
                            <option value="{{ $locale->value }}">{{ $locale->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-starts" class="form-label">{{ __('agreements.fields.starts_on') }}</label>
                    <input wire:model="form.starts_on" id="f-starts" type="date" class="form-input">
                    @error('form.starts_on') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-ends" class="form-label">{{ __('agreements.fields.ends_on') }}</label>
                    <input wire:model="form.ends_on" id="f-ends" type="date" class="form-input">
                    @error('form.ends_on') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-notice" class="form-label">{{ __('agreements.fields.notice_days') }}</label>
                    <input wire:model="form.notice_days" id="f-notice" type="number" min="0" max="365" class="form-input">
                    @error('form.notice_days') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="f-signatory" class="form-label">{{ __('agreements.fields.our_signatory') }}</label>
                    <select wire:model="form.our_signatory_id" id="f-signatory" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->signatories as $signatory)
                            <option value="{{ $signatory->id }}">{{ $signatory->full_name }} · {{ $signatory->position }}</option>
                        @endforeach
                    </select>
                </div>
                <label class="flex items-center gap-2 self-end pb-2 text-sm text-ink">
                    <input type="checkbox" wire:model="form.auto_renew" class="size-4 accent-brand-600"> {{ __('agreements.fields.auto_renew') }}
                </label>
            </div>
            @if ($this->clauseDefinitions !== [])
                <fieldset class="mt-4">
                    <legend class="form-label">{{ __('agreements.editor.clauses') }}</legend>
                    @foreach ($this->clauseDefinitions as $clause)
                        <label class="flex items-center gap-2 py-1 text-sm text-ink" wire:key="clause-{{ $clause['code'] }}">
                            <input type="checkbox" wire:model="clauses.{{ $clause['code'] }}" class="size-4 accent-brand-600">
                            {{ $clause['label'] }} @if (! empty($clause['default'])) <span class="text-xs text-muted">({{ __('agreements.editor.default_on') }})</span> @endif
                        </label>
                    @endforeach
                </fieldset>
            @endif
            <div class="mt-4 flex justify-end">
                <button type="button" wire:click="saveDraft" class="btn btn-secondary" wire:loading.attr="disabled" data-action="save-draft">{{ __('agreements.editor.save') }}</button>
            </div>
        </section>
    @endcan

    <div>
        <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('agreements.show.tabs_label') }}" class="flex gap-1 overflow-x-auto border-b border-line">
            @foreach ($tabs as $key => $label)
                <button type="button" role="tab" id="tab-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                        @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>

        <section class="card mt-4 p-5" role="tabpanel" aria-labelledby="tab-{{ $tab }}">
            @if ($tab === 'document')
                <div class="mb-4 flex flex-wrap items-center gap-3 text-sm">
                    @foreach ($locales as $locale)
                        <button type="button" wire:click="$set('lang', '{{ $locale->value }}')" @class(['rounded-md px-2 py-1', 'bg-ink text-white' => $document['locale'] === $locale->value, 'text-muted hover:text-ink' => $document['locale'] !== $locale->value]) wire:key="lang-{{ $locale->value }}">{{ $locale->label() }}</button>
                    @endforeach
                    @if (count($this->storedVersions) > 0)
                        <span class="text-muted">·</span>
                        <label for="v-select" class="text-muted">{{ __('agreements.show.stored_version') }}</label>
                        <select wire:model.live="version" id="v-select" class="form-input w-auto py-1">
                            <option value="0">{{ __('agreements.show.current_version', ['version' => $agreement->version]) }}</option>
                            @foreach ($this->storedVersions as $stored)
                                <option value="{{ $stored }}">v{{ $stored }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <p class="mb-3 text-xs text-muted" data-document-state>
                    @if ($document['stored'])
                        {{ __('agreements.show.stored_text', ['version' => $document['version']]) }} · <span class="font-mono">{{ \Illuminate\Support\Str::limit((string) $document['sha256'], 16, '…') }}</span>
                    @else
                        {{ __('agreements.show.preview_text') }}
                    @endif
                </p>
                <article class="agreement-text max-w-none rounded-md border border-line bg-white p-6 text-sm text-ink" data-agreement-text>
                    @if ($document['html'] !== '')
                        {{ \App\Support\SafeHtml::agreement($document['html']) }}
                    @else
                        <p class="text-muted">{{ __('agreements.show.no_text') }}</p>
                    @endif
                </article>
            @elseif ($tab === 'dispatches')
                <ul class="divide-y divide-line" data-dispatches>
                    @forelse ($agreement->dispatches()->with(['sender', 'recipientContact'])->get() as $dispatch)
                        <li class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm" wire:key="dp-{{ $dispatch->id }}">
                            <span class="text-ink"><span class="font-semibold">{{ $dispatch->channel->label() }}</span> · v{{ $dispatch->version }} · {{ $dispatch->recipientContact?->fullName() ?? '—' }}</span>
                            <span class="text-xs text-muted">{{ $dispatch->sender?->name }} · {{ $dispatch->sent_at->translatedFormat($dateTimeFormat) }}</span>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-muted">{{ __('agreements.show.no_dispatches') }}</li>
                    @endforelse
                </ul>
            @else
                <ol class="divide-y divide-line" data-timeline>
                    @forelse ($this->timeline as $item)
                        @php($model = $item['model'])
                        <li class="py-3 text-sm" wire:key="tl-{{ $item['kind'] }}-{{ $model->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <p class="text-ink">
                                    @if ($item['kind'] === 'transition')
                                        <span class="font-semibold">{{ $model->from_status ? __('agreements.show.transition', ['from' => \App\Enums\AgreementStatus::from($model->from_status)->label(), 'to' => \App\Enums\AgreementStatus::from($model->to_status)->label()]) : __('agreements.show.created_as', ['status' => \App\Enums\AgreementStatus::from($model->to_status)->label()]) }}</span>
                                        <span class="text-muted">· {{ $model->user?->name ?? __('activity.system') }}</span>
                                    @else
                                        <span class="font-semibold">{{ $model->eventLabel() }}</span>
                                        <span class="text-muted">· {{ $model->causer?->name ?? __('activity.system') }}</span>
                                    @endif
                                </p>
                                <time datetime="{{ $item['at']->toIso8601String() }}" class="text-xs text-muted">{{ $item['at']->translatedFormat($dateTimeFormat) }}</time>
                            </div>
                            @if ($item['kind'] === 'transition' && $model->reasonLabel())
                                <p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $model->reasonLabel() }}</p>
                            @endif
                        </li>
                    @empty
                        <li><x-empty-state :title="__('activity.empty')" icon="clock" /></li>
                    @endforelse
                </ol>
            @endif
        </section>
    </div>
</div>
