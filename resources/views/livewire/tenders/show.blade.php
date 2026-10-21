<div class="space-y-6">
    @php
        $tender = $this->tender;
        $user = auth()->user();
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $local = fn ($moment) => $tender->localTime($moment)?->translatedFormat($dateTimeFormat);
        $editable = $user->can('update', $tender);
        $isTransport = $tender->type === \App\Enums\TenderType::Transport;
        $tabs = [
            'overview' => __('tenders.tabs.overview'),
            'lots' => __('tenders.tabs.lots'),
            'setup' => __('tenders.tabs.setup'),
            'invitees' => __('tenders.tabs.invitees'),
            'qa' => __('tenders.tabs.qa'),
            'bids' => __('tenders.tabs.bids'),
            'evaluation' => __('tenders.tabs.evaluation'),
            'history' => __('tenders.tabs.history'),
        ];
        $showPanel = in_array($tender->status, [\App\Enums\TenderStatus::Draft, \App\Enums\TenderStatus::PendingApproval, \App\Enums\TenderStatus::Approved], true);
    @endphp

    <a href="{{ route('tenders.'.$tender->type->value.'.index') }}" wire:navigate class="link text-sm">&larr; {{ __('tenders.back.'.$tender->type->value) }}</a>

    <x-flash />

    <header class="card p-6" data-tender-header>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $tender->number }} · {{ $tender->type->label() }}@if ($tender->round > 1) · {{ __('tenders.round', ['round' => $tender->round]) }}@endif</p>
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">{{ $tender->title }}</h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    @php([$tone, $icon] = $tender->status->badge())
                    <x-badge :tone="$tone" :icon="$icon">{{ $tender->status->label() }}</x-badge>
                    <x-badge tone="outline">{{ $tender->method->label() }}</x-badge>
                    <x-badge tone="outline">{{ $tender->kind->label() }}</x-badge>
                    @if ($tender->version > 1) <x-badge tone="silver">{{ __('tenders.version', ['version' => $tender->version]) }}</x-badge> @endif
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2" data-tender-actions>
                @can('takeOwnership', $tender)
                    <button type="button" wire:click="takeOwnership" class="btn btn-primary" data-action="take">{{ __('tenders.actions.take') }}</button>
                @endcan
                @can('approvePublication', $tender)
                    <button type="button" wire:click="openPanel('approve')" class="btn btn-primary" data-action="approve">{{ __('tenders.actions.approve') }}</button>
                    <button type="button" wire:click="openPanel('reject')" class="btn btn-secondary" data-action="reject">{{ __('tenders.actions.reject') }}</button>
                @endcan
                @can('publish', $tender)
                    <button type="button" wire:click="publish" wire:confirm="{{ __('tenders.confirm_publish') }}" class="btn btn-primary" data-action="publish">{{ __('tenders.actions.publish') }}</button>
                @endcan
                @can('close', $tender)
                    <button type="button" wire:click="closeNow" class="btn btn-primary" data-action="close">{{ __('tenders.actions.close') }}</button>
                @endcan
                @can('execute', $tender)
                    @if ($tender->status === \App\Enums\TenderStatus::Contracted)
                        <button type="button" wire:click="startExecution" class="btn btn-secondary" data-action="start">{{ __('tenders.actions.start_execution') }}</button>
                    @endif
                    <button type="button" wire:click="complete" wire:confirm="{{ __('tenders.confirm_complete') }}" class="btn btn-secondary" data-action="complete">{{ __('tenders.actions.complete') }}</button>
                @endcan
                @can('print', $tender)
                    <a href="{{ route('tenders.notice', $tender) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('tenders.actions.print_notice') }}</a>
                @endcan
                @can('retender', $tender)
                    <button type="button" wire:click="retender" class="btn btn-secondary" data-action="retender">{{ __('tenders.actions.retender') }}</button>
                @endcan
                @can('cancel', $tender)
                    <button type="button" wire:click="openPanel('cancel')" class="btn btn-danger-outline" data-action="cancel">{{ __('tenders.actions.cancel') }}</button>
                @endcan
            </div>
        </div>

        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('tenders.fields.owner') }}</dt><dd class="mt-1 text-ink">{{ $tender->owner?->name ?? __('tenders.unassigned') }}</dd>@if ($tender->requester && $tender->requested_by !== $tender->owner_id)<dd class="text-xs text-muted">{{ __('tenders.requested_by', ['name' => $tender->requester->name]) }}</dd>@endif</div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('tenders.fields.clarification_deadline_at') }}</dt><dd class="mt-1 text-ink">{{ $local($tender->clarification_deadline_at) ?? '—' }} <span class="text-xs text-muted">{{ $tz }}</span></dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('tenders.fields.bids_due_at') }}</dt><dd class="mt-1 font-semibold text-ink" data-bids-due>{{ $local($tender->bids_due_at) ?? '—' }} <span class="text-xs font-normal text-muted">{{ $tz }}</span></dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('tenders.fields.opened_at') }}</dt><dd class="mt-1 text-ink">{{ $tender->opened_at ? $local($tender->opened_at).' '.$tz : __('tenders.sealed') }}</dd></div>
            @if ($tender->cancel_reason)
                <div class="sm:col-span-2 lg:col-span-4"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('tenders.fields.cancel_reason') }}</dt><dd class="mt-1 text-ink">{{ $tender->cancel_reason }}</dd></div>
            @endif
            @if ($tender->parent)
                <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('tenders.fields.parent') }}</dt><dd class="mt-1"><a href="{{ route('tenders.show', $tender->parent) }}" wire:navigate class="link font-mono">{{ $tender->parent->number }}</a></dd></div>
            @endif
        </dl>
    </header>

    @if ($panel !== '')
        <section class="card border-2 border-brand-600 p-5" role="dialog" aria-labelledby="panel-title" data-panel="{{ $panel }}">
            <h3 id="panel-title" class="font-semibold text-ink">{{ __('tenders.panels.'.$panel) }}</h3>
            @if (in_array($panel, ['approve', 'reject'], true))
                <p class="mt-2 text-sm text-muted">{{ __('tenders.routing.publish', ['level' => collect($publishRouting['levels'])->map->label()->implode(', ')]) }}@foreach ($publishRouting['reasons'] as $reason) · {{ __('tenders.routing.reasons.'.$reason) }}@endforeach</p>
            @endif
            <label for="panel-comment" class="form-label mt-3">{{ $panel === 'cancel' ? __('tenders.fields.cancel_reason') : __('tenders.comment') }}</label>
            <textarea wire:model="comment" id="panel-comment" rows="3" class="form-input"></textarea>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" wire:click="$set('panel', '')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                @if ($panel === 'approve')
                    <button type="button" wire:click="decidePublication(true)" class="btn btn-primary">{{ __('tenders.actions.approve') }}</button>
                @elseif ($panel === 'reject')
                    <button type="button" wire:click="decidePublication(false)" class="btn btn-primary">{{ __('tenders.actions.reject') }}</button>
                @else
                    <button type="button" wire:click="cancel" class="btn btn-danger-outline">{{ __('tenders.actions.cancel') }}</button>
                @endif
            </div>
        </section>
    @endif

    <div @class(['grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_22rem]' => $showPanel])>
        <div class="min-w-0">
            <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('tenders.tabs_label') }}" class="flex gap-1 overflow-x-auto border-b border-line">
                @foreach ($tabs as $key => $label)
                    <button type="button" role="tab" id="tab-{{ $key }}" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                            @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $tab === $key, 'border-transparent text-muted hover:text-ink' => $tab !== $key])>{{ $label }}</button>
                @endforeach
            </div>

            <section class="card mt-4 p-5" role="tabpanel" aria-labelledby="tab-{{ $tab }}">
                @if ($tab === 'overview')
                    @include('livewire.tenders.partials.overview')
                @elseif ($tab === 'lots')
                    <livewire:tenders.lot-editor :tender-id="$tender->id" :key="'lots-'.$tender->id.'-'.$tender->status->value" />
                @elseif ($tab === 'setup')
                    @include('livewire.tenders.partials.setup')
                @elseif ($tab === 'invitees')
                    @include('livewire.tenders.partials.invitees')
                @elseif ($tab === 'qa')
                    @include('livewire.tenders.partials.qa')
                @elseif ($tab === 'bids')
                    <livewire:tenders.bid-board :tender-id="$tender->id" :key="'bids-'.$tender->id.'-'.$tender->status->value" />
                @elseif ($tab === 'evaluation')
                    <livewire:tenders.evaluation :tender-id="$tender->id" :key="'eval-'.$tender->id.'-'.$tender->status->value" />
                @else
                    @include('livewire.tenders.partials.history')
                @endif
            </section>
        </div>

        @if ($showPanel)
            <aside class="card h-fit p-5" aria-labelledby="completeness-title" data-completeness>
                <h3 id="completeness-title" class="font-semibold text-ink">{{ __('tenders.completeness.title') }}</h3>
                <p class="mt-1 text-sm text-muted">{{ __('tenders.completeness.summary', ['blockers' => $blockers, 'warnings' => collect($this->issues)->where('level', 'warning')->count()]) }}</p>
                <ul class="mt-3 space-y-2">
                    @forelse ($this->issues as $issue)
                        @php($issueKey = \App\Services\Tenders\CompletenessChecker::issueKey($issue))
                        <li @class(['rounded-md border px-3 py-2 text-sm', 'border-brand-600 bg-brand-50 text-brand-800' => $issue['level'] === 'blocker', 'border-line bg-neutral-50 text-ink' => $issue['level'] === 'warning']) wire:key="issue-{{ $loop->index }}" data-issue="{{ $issue['code'] }}" data-level="{{ $issue['level'] }}">
                            <p><span class="font-mono font-semibold">{{ $issue['code'] }}</span>@if ($issue['lot']) · {{ __('tenders.lot_short', ['number' => $issue['lot']]) }}@endif · {{ \App\Services\Tenders\CompletenessChecker::message($issue) }}</p>
                            @if ($issue['level'] === 'warning' && $editable)
                                <label for="ack-{{ $loop->index }}" class="sr-only">{{ __('tenders.completeness.ack_label', ['code' => $issueKey]) }}</label>
                                <input wire:model="acks.{{ $issueKey }}" id="ack-{{ $loop->index }}" type="text" class="form-input mt-2" placeholder="{{ __('tenders.completeness.ack_placeholder') }}">
                            @elseif ($issue['level'] === 'warning' && ($tender->acknowledged_warnings[$issueKey] ?? null))
                                <p class="mt-1 text-xs text-muted">{{ $tender->acknowledged_warnings[$issueKey] }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-muted">{{ __('tenders.completeness.clean') }}</li>
                    @endforelse
                </ul>
                @can('submit', $tender)
                    <button type="button" wire:click="submit" class="btn btn-primary mt-4 w-full" @disabled($blockers > 0) data-action="submit">{{ __('tenders.actions.submit') }}</button>
                @endcan
            </aside>
        @endif
    </div>
</div>
