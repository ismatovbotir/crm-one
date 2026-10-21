<div class="space-y-6" data-requisition="{{ $requisition->number }}">
    @php($dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat())
    <a href="{{ route('requisitions.index') }}" wire:navigate class="link text-sm">&larr; {{ __('requisitions.staff.back') }}</a>
    <x-flash />

    <header class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $requisition->number }}</p>
                <h2 class="text-2xl font-semibold break-words text-ink">{{ $requisition->title ?? __('requisitions.untitled') }}</h2>
                <div class="mt-2"><x-requisitions.status-badge :status="$requisition->status" /></div>
                <p class="mt-2 text-sm text-ink">
                    @if ($requisition->customer && auth()->user()->can('view', $requisition->customer))<a href="{{ route('customers.show', $requisition->customer) }}" wire:navigate class="link">{{ $requisition->organization?->name }}</a>@else{{ $requisition->organization?->name }}@endif
                    · {{ __('requisitions.fields.requester') }}: {{ $requisition->requester?->name }} · {{ __('requisitions.fields.purchase_manager') }}: {{ $requisition->purchaseManager?->name ?? '—' }}
                </p>
                <p class="text-sm text-muted">{{ __('requisitions.fields.owner') }}: {{ $requisition->owner?->name ?? __('requisitions.unassigned') }} · {{ __('requisitions.fields.sent_at') }}: {{ $requisition->sent_at?->translatedFormat($dateTimeFormat) }}</p>
            </div>
            @if ($canManage && in_array($requisition->status, [\App\Enums\RequisitionStatus::Received, \App\Enums\RequisitionStatus::Quoting], true))
                <button type="button" wire:click="createOffer" class="btn btn-primary" data-action="create-offer">{{ __('requisitions.staff.create_offer') }}</button>
            @endif
        </div>
        @error('offer') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
            <div><dt class="text-xs text-muted uppercase">{{ __('requisitions.fields.needed_by') }}</dt><dd class="text-ink">{{ $requisition->needed_by?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-xs text-muted uppercase">{{ __('requisitions.fields.delivery_location') }}</dt><dd class="text-ink">{{ $requisition->delivery_location ?? '—' }}</dd></div>
            @if ($requisition->notes)<div class="sm:col-span-3"><dt class="text-xs text-muted uppercase">{{ __('requisitions.fields.notes') }}</dt><dd class="whitespace-pre-line text-ink">{{ $requisition->notes }}</dd></div>@endif
        </dl>
        @if ($requisition->offers->isNotEmpty())
            <p class="mt-3 text-sm">{{ __('requisitions.staff.offers') }}:
                @foreach ($requisition->offers as $offer)<a href="{{ route('offers.show', $offer) }}" wire:navigate class="link font-mono">{{ $offer->displayNumber() }}</a> ({{ $offer->status->label() }}) @endforeach
            </p>
        @endif
    </header>

    <section class="card" aria-labelledby="rqs-lines">
        <h3 id="rqs-lines" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('requisitions.lines_title') }}</h3>
        <ul class="divide-y divide-line">
            @foreach ($requisition->lines as $line)
                <li class="px-6 py-3 text-sm" wire:key="rqsl-{{ $line->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($line->isCategoryLine())
                                <span class="block text-xs text-muted">{{ __('requisitions.category_line') }}: {{ $line->category?->name }}</span>
                                <span class="text-ink">{{ $line->description }}</span>
                                <span class="block text-xs {{ $line->mappedItem ? 'text-ink' : 'font-semibold text-brand-700' }}">{{ $line->mappedItem ? '→ '.$line->mappedItem->name.' · '.$line->mappedItem->sku : __('requisitions.staff.not_mapped') }}</span>
                            @else
                                <span class="text-ink">{{ $line->item?->name }}</span> <span class="font-mono text-xs text-muted">{{ $line->item?->sku }}</span>
                            @endif
                        </div>
                        <span class="tabular-nums text-ink">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit }}</span>
                    </div>
                    @if ($canManage && $line->isCategoryLine() && in_array($requisition->status, [\App\Enums\RequisitionStatus::Received, \App\Enums\RequisitionStatus::Quoting], true))
                        <form wire:submit="mapLine({{ $line->id }})" class="mt-2 flex flex-wrap items-end gap-2" data-form="map-line">
                            <div><label for="ms-{{ $line->id }}" class="form-label">{{ __('requisitions.staff.map_to') }}</label><input wire:model.live.debounce.300ms="mapSearch.{{ $line->id }}" id="ms-{{ $line->id }}" type="search" class="form-input" placeholder="{{ __('requisitions.staff.item_search') }}"></div>
                            @php($options = $this->optionsFor($line->id))
                            @if ($options->isNotEmpty())
                                <div><label for="mi-{{ $line->id }}" class="sr-only">{{ __('requisitions.staff.map_to') }}</label><select wire:model="mapItem.{{ $line->id }}" id="mi-{{ $line->id }}" class="form-input"><option value="">—</option>@foreach ($options as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->sku }}</option>@endforeach</select></div>
                            @endif
                            <button type="submit" class="btn btn-secondary btn-sm">{{ __('requisitions.staff.map') }}</button>
                            @error('map.'.$line->id) <p class="form-error w-full">{{ $message }}</p> @enderror
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="card" aria-labelledby="rqs-comments">
            <h3 id="rqs-comments" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('requisitions.staff.clarifications') }}</h3>
            <ol class="divide-y divide-line">
                @forelse ($requisition->comments as $comment)
                    <li class="px-6 py-3 text-sm" wire:key="rqsc-{{ $comment->id }}"><p class="text-xs text-muted">{{ $comment->user?->name }} · {{ $comment->created_at?->translatedFormat($dateTimeFormat) }}</p><p class="mt-1 whitespace-pre-line text-ink">{{ $comment->body }}</p></li>
                @empty
                    <li class="px-6 py-3 text-sm text-muted">{{ __('requisitions.no_comments') }}</li>
                @endforelse
            </ol>
            @if ($canManage)
                <form wire:submit="addComment" class="space-y-2 border-t border-line p-5" novalidate>
                    <label for="rqs-comment" class="sr-only">{{ __('requisitions.staff.clarifications') }}</label>
                    <textarea wire:model="comment" id="rqs-comment" rows="2" class="form-input"></textarea>
                    @error('comment') <p class="form-error">{{ $message }}</p> @enderror
                    <div class="flex justify-end"><button type="submit" class="btn btn-secondary btn-sm">{{ __('requisitions.staff.ask') }}</button></div>
                </form>
            @endif
        </section>

        <section class="card space-y-3 p-5 text-sm" aria-labelledby="rqs-side">
            <h3 id="rqs-side" class="font-semibold text-ink">{{ __('requisitions.files') }}</h3>
            <ul class="space-y-1">
                @forelse ($requisition->attachments as $attachment)
                    <li><a href="{{ route('attachments.download', $attachment) }}" class="link" target="_blank" rel="noopener">{{ $attachment->original_name }}</a></li>
                @empty
                    <li class="text-muted">—</li>
                @endforelse
            </ul>
            @if ($canManage && in_array($requisition->status, [\App\Enums\RequisitionStatus::Received, \App\Enums\RequisitionStatus::Quoting], true))
                <form wire:submit="decline" class="space-y-2 border-t border-line pt-3">
                    <label for="rqs-decline" class="form-label">{{ __('requisitions.staff.decline_reason') }}</label>
                    <input wire:model="declineReason" id="rqs-decline" type="text" class="form-input">
                    <button type="submit" wire:confirm="{{ __('requisitions.staff.confirm_decline') }}" class="btn btn-danger-outline btn-sm">{{ __('requisitions.staff.decline') }}</button>
                </form>
            @endif
            <h3 class="border-t border-line pt-3 font-semibold text-ink">{{ __('requisitions.history') }}</h3>
            <ol class="space-y-1">
                @foreach ($requisition->transitions->sortByDesc('id') as $transition)
                    <li>{{ $transition->from_status ? __('requisitions.statuses.'.$transition->from_status).' → ' : '' }}{{ __('requisitions.statuses.'.$transition->to_status) }} <span class="text-xs text-muted">· {{ $transition->user?->name ?? __('activity.system') }} · {{ $transition->created_at?->translatedFormat($dateTimeFormat) }}</span></li>
                @endforeach
            </ol>
        </section>
    </div>
</div>
