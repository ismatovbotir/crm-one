<div class="space-y-6" data-service-asset="{{ $asset->uuid }}">
    @php($dateFormat = \App\Enums\Locale::current()->dateFormat())
    @php($dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat())
    <a href="{{ route('service.assets') }}" wire:navigate class="link text-sm">&larr; {{ __('service.staff.lookup') }}</a>
    <x-flash />

    <header class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h2 class="text-xl font-semibold break-words text-ink">{{ $asset->displayName() }} <span class="font-mono text-base text-muted">{{ $asset->serial_raw }}</span></h2>
                <p class="text-sm text-ink">{{ $asset->organization?->name }} · {{ $asset->source->label() }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <x-service.asset-badge :asset="$asset" />
                    @if (! $asset->isVerified())<x-badge tone="outline">{{ __('service.unverified') }}</x-badge>@endif
                    @if ($asset->disputed_at)<x-badge tone="danger-outline" icon="warning">{{ __('service.disputed') }}</x-badge>@endif
                </div>
            </div>
            @if ($canManage)
                <div class="flex flex-wrap gap-2">
                    @if (! $asset->isVerified())<button type="button" wire:click="verify" class="btn btn-primary btn-sm" data-action="verify">{{ __('service.staff.verify') }}</button>@endif
                    @if ($asset->status->canTransitionTo(\App\Enums\AssetStatus::OutOfService))<button type="button" wire:click="writeOff" wire:confirm="{{ __('service.staff.confirm_write_off') }}" class="btn btn-danger-outline btn-sm">{{ __('service.staff.write_off') }}</button>@endif
                    @if ($asset->status === \App\Enums\AssetStatus::OutOfService)<button type="button" wire:click="reactivate" class="btn btn-secondary btn-sm">{{ __('service.staff.reactivate') }}</button>@endif
                </div>
            @endif
        </div>
        @if ($canManage)
            <div class="mt-3"><label for="as-comment" class="form-label">{{ __('service.staff.comment') }}</label><input wire:model="comment" id="as-comment" type="text" class="form-input" maxlength="2000">@error('comment') <p class="form-error" role="alert">{{ $message }}</p> @enderror</div>
        @endif
        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.location') }}</dt><dd class="text-ink">{{ $asset->location ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.sold_at') }}</dt><dd class="text-ink">{{ $asset->sold_at?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.warranty_until') }}</dt><dd class="text-ink">{{ $asset->warranty_until?->translatedFormat($dateFormat) ?? __('service.no_warranty') }}</dd></div>
            <div><dt class="text-xs text-muted uppercase">{{ __('service.fields.registry') }}</dt><dd>@if ($asset->itemSerial && auth()->user()->can('view', $asset->itemSerial))<a href="{{ route('serials.show', $asset->itemSerial) }}" wire:navigate class="link font-mono">{{ $asset->itemSerial->serial_normalized }}</a>@else<span class="text-muted">{{ $asset->item_serial_id ? __('service.linked') : '—' }}</span>@endif</dd></div>
        </dl>
        @if ($asset->photo_path)<img src="{{ route('service.photos.asset', $asset) }}" alt="{{ __('service.fields.photo') }}" class="mt-3 size-32 rounded-md border border-line object-cover">@endif
    </header>

    @if ($canManage && ! $asset->openTicket())
        <form wire:submit="openTicket" class="card grid gap-3 p-5 sm:grid-cols-2" data-form="staff-ticket" novalidate>
            <h3 class="font-semibold text-ink sm:col-span-2">{{ __('service.staff.open_ticket') }}</h3>
            <div class="sm:col-span-2"><label for="so-problem" class="form-label">{{ __('service.fields.problem') }}</label><textarea wire:model="report.problem" id="so-problem" rows="3" class="form-input"></textarea>@error('report.problem') <p class="form-error" role="alert">{{ $message }}</p> @enderror</div>
            <div><label for="so-urgency" class="form-label">{{ __('service.fields.urgency') }}</label><select wire:model="report.urgency" id="so-urgency" class="form-input">@foreach ($urgencies as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
            <div><label for="so-contact" class="form-label">{{ __('service.fields.contact_name') }}</label><input wire:model="report.contact_name" id="so-contact" type="text" class="form-input"></div>
            <div class="flex justify-end sm:col-span-2"><button type="submit" class="btn btn-primary btn-sm">{{ __('service.staff.open_ticket') }}</button></div>
        </form>
    @endif

    <section class="card" aria-labelledby="sa-tickets">
        <h3 id="sa-tickets" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('service.tickets_title') }}</h3>
        <ul class="divide-y divide-line">
            @forelse ($asset->tickets as $ticket)
                <li class="flex flex-wrap items-center justify-between gap-2 px-6 py-3 text-sm" wire:key="sat-{{ $ticket->id }}"><a href="{{ route('service.tickets.show', $ticket) }}" wire:navigate class="link font-mono">{{ $ticket->number }}</a><x-service.ticket-badge :ticket="$ticket" /></li>
            @empty
                <li class="px-6 py-3 text-sm text-muted">{{ __('service.no_tickets') }}</li>
            @endforelse
        </ul>
    </section>

    <section class="card" aria-labelledby="sa-history">
        <h3 id="sa-history" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('service.history') }}</h3>
        <ol class="divide-y divide-line">
            @foreach ($asset->events as $event)
                <li class="px-6 py-3 text-sm" wire:key="sae-{{ $event->id }}">
                    <p class="text-ink"><span class="font-semibold">{{ $event->label() }}</span>@if ($event->to_status) · {{ $event->from_status?->label() ?? '—' }} → {{ $event->to_status->label() }}@endif
                        <span class="text-muted">· {{ $event->user?->name ?? __('activity.system') }} · {{ $event->created_at?->translatedFormat($dateTimeFormat) }}</span></p>
                    @if ($event->comment)<p class="mt-1 text-xs whitespace-pre-line text-muted">{{ $event->comment }}</p>@endif
                </li>
            @endforeach
        </ol>
    </section>
</div>
