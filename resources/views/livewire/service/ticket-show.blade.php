<div class="space-y-6" data-service-ticket="{{ $ticket->number }}">
    @php($dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat())
    @php($dateFormat = \App\Enums\Locale::current()->dateFormat())
    <a href="{{ route('service.tickets') }}" wire:navigate class="link text-sm">&larr; {{ __('service.staff.back') }}</a>
    <x-flash />

    <header class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $ticket->number }}</p>
                <h2 class="text-2xl font-semibold break-words text-ink">{{ $ticket->asset->displayName() }} <span class="font-mono text-base text-muted">{{ $ticket->asset->serial_raw }}</span></h2>
                <div class="mt-2 flex flex-wrap gap-2">
                    <x-service.ticket-badge :ticket="$ticket" />
                    <x-badge tone="outline">{{ $ticket->urgency->label() }}</x-badge>
                    <x-badge :tone="$ticket->under_warranty ? 'dark' : 'outline'" data-warranty>{{ $ticket->under_warranty ? __('service.under_warranty') : __('service.not_under_warranty') }}</x-badge>
                    @if ($ticket->isOverdue())<x-badge tone="danger-outline" icon="clock" data-overdue>{{ __('service.staff.overdue') }}</x-badge>@endif
                </div>
                <p class="mt-2 text-sm text-ink">
                    @if ($ticket->customer && auth()->user()->can('view', $ticket->customer))
                        <a href="{{ route('customers.show', ['customer' => $ticket->customer, 'tab' => 'equipment']) }}" wire:navigate class="link">{{ $ticket->organization?->name }}</a>
                    @else
                        {{ $ticket->organization?->name }}
                    @endif
                    · <a href="{{ route('service.assets.show', $ticket->asset) }}" wire:navigate class="link">{{ __('service.staff.asset_page') }}</a>
                </p>
            </div>
            <a href="{{ route('service.tickets.print', $ticket) }}" target="_blank" rel="noopener" class="btn btn-secondary">{{ __('service.print_receipt') }}</a>
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.reported') }}</dt><dd class="text-ink">{{ $ticket->created_at?->translatedFormat($dateTimeFormat) }} · {{ $ticket->reporter?->name ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.contact_name') }}</dt><dd class="text-ink">{{ collect([$ticket->contact_name, $ticket->contact_phone])->filter()->join(' · ') ?: '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.location') }}</dt><dd class="text-ink">{{ $ticket->location ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.warranty_until') }}</dt><dd class="text-ink">{{ $ticket->warranty_until?->translatedFormat($dateFormat) ?? __('service.no_warranty') }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.respond_due') }}</dt><dd class="text-ink">{{ $ticket->respond_due_at?->translatedFormat($dateTimeFormat) ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.repair_due') }}</dt><dd class="text-ink">{{ $ticket->repair_due_at?->translatedFormat($dateTimeFormat) ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.total') }}</dt><dd class="text-ink" data-ticket-total>{{ $ticket->money($ticket->totalMinor())->format() }}</dd></div>
            @if ($ticket->onec_state)<div><dt class="text-xs text-muted uppercase">1C</dt><dd class="text-ink">{{ $ticket->onec_state }} {{ $ticket->onec_number }}</dd></div>@endif
        </dl>
        <p class="mt-4 whitespace-pre-line text-ink">{{ $ticket->problem }}</p>
        @if ($ticket->photos->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($ticket->photos as $photo)
                    <a href="{{ route('service.photos.ticket', $photo) }}" target="_blank" rel="noopener"><img src="{{ route('service.photos.ticket', $photo) }}" alt="{{ __('service.fields.photo') }} {{ $loop->iteration }}" class="size-24 rounded-md border border-line object-cover"></a>
                @endforeach
            </div>
        @endif
    </header>

    <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-6">
            @if ($canManage && $targets !== [])
                <form wire:submit="moveTicket" class="card flex flex-wrap items-end gap-3 p-5" data-form="ticket-transition" novalidate>
                    <div>
                        <label for="tt-target" class="form-label">{{ __('service.staff.move_to') }}</label>
                        <select wire:model="target" id="tt-target" class="form-input">
                            <option value="">—</option>
                            @foreach ($targets as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                        </select>
                        @error('target') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-64 flex-1">
                        <label for="tt-text" class="form-label">{{ __('service.staff.transition_text') }}</label>
                        <input wire:model="transitionText" id="tt-text" type="text" class="form-input" maxlength="2000">
                    </div>
                    @if (isset($targets['waiting_for_parts']))
                        <div class="grid w-full gap-3 sm:grid-cols-3" data-queue-fields>
                            <p class="text-xs text-muted sm:col-span-3">{{ __('service.queue.enter_hint') }}</p>
                            <div class="sm:col-span-2">
                                <label for="tq-need" class="form-label">{{ __('service.queue.need') }}</label>
                                <input wire:model="queueNeed" id="tq-need" type="text" maxlength="255" class="form-input">
                            </div>
                            <div>
                                <label for="tq-eta" class="form-label">{{ __('service.queue.eta') }}</label>
                                <input wire:model="queueEta" id="tq-eta" type="date" class="form-input">
                                @error('queueEta') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    @endif
                    <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
                </form>
            @endif

            @if ($ticket->status === \App\Enums\ServiceTicketStatus::WaitingForParts)
                <section class="card p-5" aria-labelledby="tk-queue" data-ticket-queue>
                    <h3 id="tk-queue" class="font-semibold text-ink">{{ __('service.queue.title') }}</h3>
                    <p class="mt-1 text-sm text-ink" data-queue-position>{{ __('service.queue.position', ['position' => $queuePositionNow ?? '—']) }} · {{ __('service.queue.since', ['date' => $ticket->queued_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—']) }}</p>
                    @if ($canManage)
                        <form wire:submit="saveQueueDetails" class="mt-4 grid gap-3 sm:grid-cols-3" novalidate>
                            <div class="sm:col-span-2">
                                <label for="tq-need2" class="form-label">{{ __('service.queue.need') }}</label>
                                <input wire:model="queueNeed" id="tq-need2" type="text" maxlength="255" class="form-input">
                                @error('queueNeed') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="tq-eta2" class="form-label">{{ __('service.queue.eta') }}</label>
                                <input wire:model="queueEta" id="tq-eta2" type="date" class="form-input">
                            </div>
                            <div class="sm:col-span-2">
                                <label for="tq-item" class="form-label">{{ __('service.queue.item') }}</label>
                                <input wire:model.live.debounce.300ms="partSearch" id="tq-item-search" type="search" class="form-input" placeholder="{{ __('service.staff.part_search') }}" aria-label="{{ __('service.staff.part_search') }}">
                                <select wire:model="queueItemId" id="tq-item" class="form-input mt-2">
                                    <option value="">{{ $ticket->queueItem?->name ?? '—' }}</option>
                                    @foreach ($this->partOptions as $option)<option value="{{ $option->id }}" wire:key="qi-{{ $option->id }}">{{ $option->name }} · {{ $option->sku }}</option>@endforeach
                                </select>
                            </div>
                            <div class="flex items-end"><button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button></div>
                        </form>
                        <form wire:submit="reorderQueue" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4" data-queue-reorder novalidate>
                            <div>
                                <label for="tq-pos" class="form-label">{{ __('service.queue.position_field') }}</label>
                                <input wire:model="queuePosition" id="tq-pos" type="number" min="1" class="form-input w-24">
                                @error('queuePosition') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div class="min-w-64 flex-1">
                                <label for="tq-reason" class="form-label">{{ __('service.queue.reason') }}</label>
                                <input wire:model="queueReason" id="tq-reason" type="text" maxlength="500" class="form-input">
                                @error('queueReason') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-secondary">{{ __('service.queue.reorder') }}</button>
                        </form>
                    @endif
                </section>
            @endif

            <section class="card" aria-labelledby="tk-parts">
                <h3 id="tk-parts" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('service.staff.parts') }}</h3>
                <table class="min-w-full divide-y divide-line text-sm">
                    <tbody class="divide-y divide-line">
                        @forelse ($ticket->parts as $part)
                            <tr wire:key="pp-{{ $part->id }}"><td class="px-6 py-2 text-ink">{{ $part->description }}</td><td class="px-6 py-2 text-right tabular-nums">{{ \App\Support\Quantity::trimmed((string) $part->quantity) }}</td><td class="px-6 py-2 text-right tabular-nums">{{ $ticket->money($part->unit_price_minor)->format() }}</td></tr>
                        @empty
                            <tr><td class="px-6 py-3 text-muted">{{ __('service.staff.no_parts') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($canManage && $ticket->status->isOpen())
                    <form wire:submit="addPart" class="grid gap-3 border-t border-line p-5 sm:grid-cols-4" novalidate>
                        <div class="sm:col-span-2">
                            <label for="pp-search" class="form-label">{{ __('service.fields.part') }}</label>
                            <input wire:model.live.debounce.300ms="partSearch" id="pp-search" type="search" class="form-input" placeholder="{{ __('service.staff.part_search') }}">
                            @if ($this->partOptions->isNotEmpty())
                                <select wire:model="part.item_id" aria-label="{{ __('service.fields.part') }}" class="form-input mt-1"><option value="">—</option>@foreach ($this->partOptions as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->sku }}</option>@endforeach</select>
                            @endif
                            <input wire:model="part.description" aria-label="{{ __('service.staff.part_description') }}" type="text" class="form-input mt-1" placeholder="{{ __('service.staff.part_description') }}">
                            @error('part.description') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div><label for="pp-qty" class="form-label">{{ __('service.fields.quantity') }}</label><input wire:model="part.quantity" id="pp-qty" type="text" inputmode="decimal" class="form-input">@error('part.quantity') <p class="form-error">{{ $message }}</p> @enderror</div>
                        <div><label for="pp-price" class="form-label">{{ __('service.fields.unit_price') }} ({{ $ticket->currency }})</label><input wire:model="part.unit_price" id="pp-price" type="text" inputmode="decimal" class="form-input" @disabled($ticket->under_warranty)></div>
                        <div class="flex justify-end sm:col-span-4"><button type="submit" class="btn btn-secondary btn-sm">{{ __('service.staff.add_part') }}</button></div>
                    </form>
                    <form wire:submit="saveWork" class="grid gap-3 border-t border-line p-5 sm:grid-cols-3" novalidate>
                        <div><label for="tw-labor" class="form-label">{{ __('service.fields.labor') }} ({{ $ticket->currency }})</label><input wire:model="labor" id="tw-labor" type="text" inputmode="decimal" class="form-input" @disabled($ticket->under_warranty)>@if ($ticket->under_warranty)<p class="mt-1 text-xs text-muted">{{ __('service.staff.warranty_free') }}</p>@endif</div>
                        <div class="sm:col-span-2"><label for="tw-repl" class="form-label">{{ __('service.fields.replacement') }}</label><input wire:model="replacementNote" id="tw-repl" type="text" class="form-input"></div>
                        <div class="flex justify-end sm:col-span-3"><button type="submit" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button></div>
                    </form>
                @endif
            </section>

            <section class="card" aria-labelledby="tk-notes">
                <h3 id="tk-notes" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('service.staff.notes') }}</h3>
                @if ($canManage)
                    <form wire:submit="addNote" class="space-y-2 border-b border-line p-5" novalidate>
                        <label for="tn-body" class="sr-only">{{ __('service.fields.note') }}</label>
                        <textarea wire:model="note" id="tn-body" rows="2" class="form-input" maxlength="5000"></textarea>
                        @error('note') <p class="form-error">{{ $message }}</p> @enderror
                        <div class="flex items-center justify-between gap-3">
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="noteInternal" type="checkbox" class="rounded border-line"> {{ __('service.staff.internal_note') }}</label>
                            <button type="submit" class="btn btn-secondary btn-sm">{{ __('service.staff.add_note') }}</button>
                        </div>
                    </form>
                @endif
                <ol class="divide-y divide-line">
                    @forelse ($ticket->notes as $note)
                        <li class="px-6 py-3 text-sm" wire:key="tn-{{ $note->id }}">
                            <p class="text-xs text-muted">{{ $note->user?->name }} · {{ $note->created_at?->translatedFormat($dateTimeFormat) }} · {{ $note->is_internal ? __('service.staff.internal') : __('service.staff.visible_to_customer') }}</p>
                            <p class="mt-1 whitespace-pre-line text-ink">{{ $note->body }}</p>
                        </li>
                    @empty
                        <li class="px-6 py-3 text-sm text-muted">{{ __('service.staff.no_notes') }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card p-5 text-sm" aria-labelledby="tk-assign">
                <h3 id="tk-assign" class="font-semibold text-ink">{{ __('service.fields.assignee') }}</h3>
                @if ($canManage)
                    <form wire:submit="assign" class="mt-3 flex gap-2">
                        <label for="ta-user" class="sr-only">{{ __('service.fields.assignee') }}</label>
                        <select wire:model="assignee" id="ta-user" class="form-input"><option value="">—</option>@foreach ($this->engineers as $engineer)<option value="{{ $engineer->id }}">{{ $engineer->name }}</option>@endforeach</select>
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                    </form>
                @else
                    <p class="mt-2 text-ink">{{ $ticket->assignee?->name ?? '—' }}</p>
                @endif
            </section>

            <section class="card p-5 text-sm" aria-labelledby="tk-docs">
                <h3 id="tk-docs" class="font-semibold text-ink">{{ __('service.staff.documents') }}</h3>
                <ul class="mt-2 space-y-1">
                    @forelse ($ticket->attachments as $attachment)
                        <li><a href="{{ route('attachments.download', $attachment) }}" target="_blank" rel="noopener" class="link">{{ $attachment->original_name }}</a></li>
                    @empty
                        <li class="text-muted">—</li>
                    @endforelse
                </ul>
                @if ($canManage)
                    <form wire:submit="attach" class="mt-3 space-y-2">
                        <label for="td-file" class="sr-only">{{ __('service.staff.documents') }}</label>
                        <input wire:model="document" id="td-file" type="file" class="form-input">
                        @error('document') <p class="form-error">{{ $message }}</p> @enderror
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('service.staff.attach') }}</button>
                    </form>
                @endif
            </section>

            <section class="card p-5 text-sm" aria-labelledby="tk-history">
                <h3 id="tk-history" class="font-semibold text-ink">{{ __('service.history') }}</h3>
                <ol class="mt-2 space-y-2">
                    @foreach ($ticket->transitions->sortByDesc('id') as $transition)
                        <li><span class="text-ink">{{ $transition->from_status ? __('service.ticket_statuses.'.$transition->from_status).' → ' : '' }}{{ __('service.ticket_statuses.'.$transition->to_status) }}</span>
                            <span class="block text-xs text-muted">{{ $transition->user?->name ?? __('activity.system') }} · {{ $transition->created_at?->translatedFormat($dateTimeFormat) }}</span>
                            @if ($transition->reason)<span class="block text-xs text-muted">{{ $transition->reason }}</span>@endif</li>
                    @endforeach
                </ol>
            </section>
        </aside>
    </div>
</div>
