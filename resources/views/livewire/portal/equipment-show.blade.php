<div class="space-y-6" data-portal-asset="{{ $asset->uuid }}">
    @php($dateFormat = \App\Enums\Locale::current()->dateFormat())
    @php($dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat())
    <a href="{{ route('portal.equipment') }}" wire:navigate class="link text-sm">&larr; {{ __('service.portal.back') }}</a>
    <x-flash />

    <section class="card p-6" aria-labelledby="asset-title">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 id="asset-title" class="text-xl font-semibold break-words text-ink">{{ $asset->displayName() }}</h2>
                <p class="font-mono text-sm text-muted">{{ $asset->serial_raw }}</p>
                <div class="mt-2"><x-service.asset-badge :asset="$asset" /></div>
                @if (! $asset->isVerified())<p class="mt-2 text-xs text-muted">{{ __('service.unverified_hint') }}</p>@endif
            </div>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @if (! $asset->openTicket() && in_array($asset->status, [\App\Enums\AssetStatus::InUse, \App\Enums\AssetStatus::RepairedReady, \App\Enums\AssetStatus::NeedsRepair], true))
                        <button type="button" wire:click="$toggle('reporting')" class="btn btn-primary" data-action="report-problem" aria-expanded="{{ $reporting ? 'true' : 'false' }}">{{ __('service.portal.report') }}</button>
                    @endif
                </div>
            @endif
        </div>

        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.location') }}</dt><dd class="text-ink">{{ $asset->location ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.source') }}</dt><dd class="text-ink">{{ $asset->source->label() }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.sold_at') }}</dt><dd class="text-ink">{{ $asset->sold_at?->translatedFormat($dateFormat) ?? ($asset->purchased_on?->translatedFormat($dateFormat) ?? '—') }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.warranty_until') }}</dt>
                <dd class="font-semibold text-ink" data-warranty>
                    @if ($asset->warranty_until)
                        {{ $asset->warranty_until->translatedFormat($dateFormat) }} · {{ $asset->underWarranty() ? __('service.warranty_active') : __('service.warranty_expired') }}
                    @else
                        {{ __('service.no_warranty') }}
                    @endif
                </dd>
            </div>
            @if ($asset->order)<div><dt class="text-xs text-muted uppercase">{{ __('service.fields.order') }}</dt><dd><a href="{{ route('portal.orders.show', $asset->order) }}" wire:navigate class="link font-mono">{{ $asset->order->number }}</a></dd></div>@endif
        </dl>
    </section>

    @if ($canManage && $reporting)
        <form wire:submit="reportProblem" class="card grid gap-4 p-5 sm:grid-cols-2" data-form="report-problem" novalidate>
            <h3 class="font-semibold text-ink sm:col-span-2">{{ __('service.portal.report_title') }}</h3>
            <div class="sm:col-span-2">
                <label for="rp-problem" class="form-label">{{ __('service.fields.problem') }}</label>
                <textarea wire:model="report.problem" id="rp-problem" rows="4" class="form-input" maxlength="5000"></textarea>
                @error('report.problem') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="rp-urgency" class="form-label">{{ __('service.fields.urgency') }}</label>
                <select wire:model="report.urgency" id="rp-urgency" class="form-input">
                    @foreach ($urgencies as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="rp-location" class="form-label">{{ __('service.fields.location') }}</label>
                <input wire:model="report.location" id="rp-location" type="text" class="form-input" placeholder="{{ $asset->location }}">
            </div>
            <div>
                <label for="rp-contact" class="form-label">{{ __('service.fields.contact_name') }}</label>
                <input wire:model="report.contact_name" id="rp-contact" type="text" class="form-input">
            </div>
            <div>
                <label for="rp-phone" class="form-label">{{ __('service.fields.contact_phone') }}</label>
                <input wire:model="report.contact_phone" id="rp-phone" type="tel" class="form-input">
            </div>
            <div class="sm:col-span-2">
                <label for="rp-photos" class="form-label">{{ __('service.fields.photos', ['max' => config('service.max_photos')]) }}</label>
                <input wire:model="photos" id="rp-photos" type="file" multiple accept="image/jpeg,image/png,image/webp" class="form-input">
                @error('photos') <p class="form-error">{{ $message }}</p> @enderror
                @error('photos.*') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end sm:col-span-2"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('service.portal.report_submit') }}</button></div>
        </form>
    @endif

    <section class="card" aria-labelledby="asset-tickets">
        <h3 id="asset-tickets" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('service.tickets_title') }}</h3>
        <ul class="divide-y divide-line">
            @forelse ($asset->tickets as $ticket)
                <li class="px-6 py-4 text-sm" wire:key="pt-{{ $ticket->id }}" data-ticket="{{ $ticket->number }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p><span class="font-mono font-semibold text-ink">{{ $ticket->number }}</span> <x-service.ticket-badge :ticket="$ticket" />
                            @if ($ticket->under_warranty)<x-badge tone="silver">{{ __('service.under_warranty') }}</x-badge>@endif</p>
                        <div class="flex gap-2">
                            <a href="{{ route('portal.service.print', $ticket) }}" class="btn btn-secondary btn-sm" target="_blank" rel="noopener">{{ __('service.print_receipt') }}</a>
                            @if ($canManage && $ticket->status->cancellableByCustomer())
                                <button type="button" wire:click="cancelTicket({{ $ticket->id }})" wire:confirm="{{ __('service.portal.confirm_cancel') }}" class="btn btn-danger-outline btn-sm">{{ __('service.portal.cancel') }}</button>
                            @endif
                        </div>
                    </div>
                    @if ($ticket->status === \App\Enums\ServiceTicketStatus::WaitingForParts)
                        {{-- Spec 09 section 15: the customer sees the queue position and the estimated date. --}}
                        <p class="mt-2 rounded-md border border-line px-3 py-2 text-ink" data-portal-queue>
                            {{ __('service.queue.portal_position', ['position' => app(\App\Services\Equipment\ServiceQueue::class)->position($ticket) ?? '—']) }}
                            @if ($ticket->queue_eta) · {{ __('service.queue.portal_eta', ['date' => $ticket->queue_eta->translatedFormat(\App\Enums\Locale::current()->dateFormat())]) }} @endif
                        </p>
                    @endif
                    <p class="mt-1 whitespace-pre-line text-ink">{{ $ticket->problem }}</p>
                    <p class="mt-1 text-xs text-muted">{{ $ticket->created_at?->translatedFormat($dateTimeFormat) }}</p>
                    @if ($ticket->resolution)<p class="mt-2 text-ink"><span class="font-medium">{{ __('service.fields.resolution') }}:</span> {{ $ticket->resolution }}</p>@endif
                    @if ($ticket->reject_reason)<p class="mt-2 text-ink"><span class="font-medium">{{ __('service.fields.reject_reason') }}:</span> {{ $ticket->reject_reason }}</p>@endif
                    @foreach ($ticket->notes->where('is_internal', false) as $note)
                        <p class="mt-2 rounded-md bg-sidebar px-3 py-2 text-ink" data-public-note>{{ $note->body }}</p>
                    @endforeach
                </li>
            @empty
                <li class="px-6 py-4 text-sm text-muted">{{ __('service.no_tickets') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card" aria-labelledby="asset-history">
        <h3 id="asset-history" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('service.history') }}</h3>
        <ol class="divide-y divide-line" data-asset-history>
            @foreach ($asset->events->whereNotIn('event', ['claim_refused', 'disputed']) as $event)
                <li class="px-6 py-3 text-sm" wire:key="ev-{{ $event->id }}">
                    <div class="flex flex-wrap justify-between gap-2">
                        <p class="text-ink"><span class="font-semibold">{{ $event->label() }}</span>
                            @if ($event->to_status) · {{ $event->from_status?->label() ?? '—' }} → {{ $event->to_status->label() }}@endif</p>
                        <time class="text-xs text-muted" datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->translatedFormat($dateTimeFormat) }}</time>
                    </div>
                    @if ($event->comment)<p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $event->comment }}</p>@endif
                </li>
            @endforeach
        </ol>
    </section>
</div>
