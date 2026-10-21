<div class="space-y-6" data-portal-requisition="{{ $requisition->number }}">
    @php($dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat())
    <a href="{{ route('portal.requisitions') }}" wire:navigate class="link text-sm">&larr; {{ __('requisitions.portal.back') }}</a>
    <x-flash />

    <header class="card p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="font-mono text-sm text-muted">{{ $requisition->number }}</p>
                <h2 class="text-xl font-semibold text-ink">{{ $requisition->title ?? __('requisitions.untitled') }}</h2>
                <div class="mt-2"><x-requisitions.status-badge :status="$requisition->status" :portal="true" /></div>
                <p class="mt-2 text-sm text-muted">{{ __('requisitions.fields.requester') }}: {{ $requisition->requester?->name }}@if ($requisition->purchaseManager) · {{ __('requisitions.fields.purchase_manager') }}: {{ $requisition->purchaseManager->name }}@endif</p>
                @if ($requisition->return_comment && $requisition->status === \App\Enums\RequisitionStatus::Returned)<p class="mt-2 rounded-md bg-sidebar px-3 py-2 text-sm text-ink" data-return-comment>{{ $requisition->return_comment }}</p>@endif
                @if ($requisition->reject_reason)<p class="mt-2 text-sm text-ink">{{ __('requisitions.fields.reason') }}: {{ $requisition->reject_reason }}</p>@endif
                @if ($requisition->decline_reason)<p class="mt-2 text-sm text-ink">{{ __('requisitions.fields.reason') }}: {{ $requisition->decline_reason }}</p>@endif
                @if ($requisition->mergedInto)<p class="mt-2 text-sm text-ink">{{ __('requisitions.merged_into') }} <a href="{{ route('portal.requisitions.show', $requisition->mergedInto) }}" wire:navigate class="link font-mono">{{ $requisition->mergedInto->number }}</a></p>@endif
            </div>
            @if ($offer)
                <a href="{{ route('portal.offers.show', $offer) }}" wire:navigate class="btn btn-primary" data-offer-link>{{ __('requisitions.portal.open_offer', ['number' => $offer->displayNumber()]) }}</a>
            @endif
        </div>

        @if ($canEdit)
            <form wire:submit="saveHeader" class="mt-5 grid gap-3 sm:grid-cols-2" novalidate>
                <div><label for="rh-title" class="form-label">{{ __('requisitions.fields.title') }}</label><input wire:model="header.title" id="rh-title" type="text" class="form-input"></div>
                <div><label for="rh-needed" class="form-label">{{ __('requisitions.fields.needed_by') }}</label><input wire:model="header.needed_by" id="rh-needed" type="date" class="form-input"></div>
                <div class="sm:col-span-2"><label for="rh-location" class="form-label">{{ __('requisitions.fields.delivery_location') }}</label><input wire:model="header.delivery_location" id="rh-location" type="text" class="form-input"></div>
                <div class="sm:col-span-2"><label for="rh-notes" class="form-label">{{ __('requisitions.fields.notes') }}</label><textarea wire:model="header.notes" id="rh-notes" rows="2" class="form-input"></textarea></div>
                <div class="flex justify-end sm:col-span-2"><button type="submit" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button></div>
            </form>
        @else
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-muted uppercase">{{ __('requisitions.fields.needed_by') }}</dt><dd class="text-ink">{{ $requisition->needed_by?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-xs text-muted uppercase">{{ __('requisitions.fields.delivery_location') }}</dt><dd class="text-ink">{{ $requisition->delivery_location ?? '—' }}</dd></div>
            </dl>
        @endif
    </header>

    <section class="card" aria-labelledby="rq-lines">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-6 py-4">
            <h3 id="rq-lines" class="font-semibold text-ink">{{ __('requisitions.lines_title') }}</h3>
            @if ($canEdit)<a href="{{ route('portal.catalog') }}" wire:navigate class="btn btn-secondary btn-sm">{{ __('requisitions.portal.add_from_catalog') }}</a>@endif
        </div>
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="text-left text-xs text-muted uppercase"><tr>
                @if ($canPurchase && in_array($requisition->status, [\App\Enums\RequisitionStatus::Submitted, \App\Enums\RequisitionStatus::Approved], true))<th scope="col" class="px-6 py-2"><span class="sr-only">{{ __('requisitions.portal.select') }}</span></th>@endif
                <th scope="col" class="px-6 py-2">{{ __('requisitions.fields.line') }}</th><th scope="col" class="px-6 py-2 text-right">{{ __('requisitions.fields.quantity') }}</th><th scope="col" class="px-6 py-2"></th>
            </tr></thead>
            <tbody class="divide-y divide-line">
                @forelse ($requisition->lines as $line)
                    <tr wire:key="rl-{{ $line->id }}" data-line-kind="{{ $line->kind }}">
                        @if ($canPurchase && in_array($requisition->status, [\App\Enums\RequisitionStatus::Submitted, \App\Enums\RequisitionStatus::Approved], true))<td class="px-6 py-2"><input wire:model="splitLines" value="{{ $line->id }}" type="checkbox" class="rounded border-line" aria-label="{{ __('requisitions.portal.select') }}"></td>@endif
                        <td class="px-6 py-2 text-ink">
                            @if ($line->isCategoryLine())
                                <span class="block text-xs text-muted">{{ __('requisitions.category_line') }}: {{ $line->category?->portalName() }}</span>{{ $line->description }}
                                @if ($line->mappedItem)<span class="block text-xs text-muted">→ {{ $line->mappedItem->name }}</span>@endif
                            @else
                                {{ $line->item?->name }} <span class="font-mono text-xs text-muted">{{ $line->item?->sku }}</span>
                            @endif
                        </td>
                        <td class="px-6 py-2 text-right whitespace-nowrap">
                            @if ($canEdit)
                                <form wire:submit="saveQuantity({{ $line->id }})" class="inline-flex items-center gap-1">
                                    <label for="lq-{{ $line->id }}" class="sr-only">{{ __('requisitions.fields.quantity') }}</label>
                                    <input wire:model="quantities.{{ $line->id }}" id="lq-{{ $line->id }}" type="text" inputmode="decimal" class="form-input w-24 text-right" placeholder="{{ \App\Support\Quantity::trimmed((string) $line->quantity) }}">
                                    <button type="submit" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                                </form>
                            @else
                                {{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit }}
                            @endif
                        </td>
                        <td class="px-6 py-2 text-right">@if ($canEdit)<button type="button" wire:click="removeLine({{ $line->id }})" class="btn btn-danger-outline btn-sm">{{ __('requisitions.portal.remove') }}</button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-6 py-4 text-muted">{{ __('requisitions.portal.no_lines') }}</td></tr>
                @endforelse
            </tbody>
        </table>
        @error('splitLines') <p class="form-error px-6 pb-3" role="alert">{{ $message }}</p> @enderror
    </section>

    <section class="card space-y-3 p-5" aria-labelledby="rq-actions">
        <h3 id="rq-actions" class="font-semibold text-ink">{{ __('requisitions.portal.actions') }}</h3>
        @if ($canPurchase && in_array($requisition->status, [\App\Enums\RequisitionStatus::Submitted, \App\Enums\RequisitionStatus::Approved], true))
            <div><label for="rq-decision" class="form-label">{{ __('requisitions.portal.decision_text') }}</label><input wire:model="decisionText" id="rq-decision" type="text" class="form-input" maxlength="2000"></div>
        @endif
        @error('decisionText') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        <div class="flex flex-wrap gap-2">
            @if ($canSubmit)<button type="button" wire:click="submit" class="btn btn-primary" data-action="submit">{{ __('requisitions.portal.submit') }}</button>@endif
            @if ($canPurchase)
                @if ($requisition->status === \App\Enums\RequisitionStatus::Submitted)<button type="button" wire:click="approve" class="btn btn-secondary" data-action="approve">{{ __('requisitions.portal.approve') }}</button>@endif
                @if (in_array($requisition->status, [\App\Enums\RequisitionStatus::Submitted, \App\Enums\RequisitionStatus::Approved], true))
                    <button type="button" wire:click="returnToRequester" class="btn btn-secondary" data-action="return">{{ __('requisitions.portal.return') }}</button>
                    <button type="button" wire:click="split" class="btn btn-secondary" data-action="split">{{ __('requisitions.portal.split') }}</button>
                @endif
                @if ($requisition->status === \App\Enums\RequisitionStatus::Submitted)<button type="button" wire:click="reject" wire:confirm="{{ __('requisitions.portal.confirm_reject') }}" class="btn btn-danger-outline" data-action="reject">{{ __('requisitions.portal.reject') }}</button>@endif
                @if (in_array($requisition->status, [\App\Enums\RequisitionStatus::Submitted, \App\Enums\RequisitionStatus::Approved, \App\Enums\RequisitionStatus::Draft], true) && ($requisition->status !== \App\Enums\RequisitionStatus::Draft || $requisition->requester_id === auth('portal')->id()))
                    <button type="button" wire:click="send" wire:confirm="{{ __('requisitions.portal.confirm_send') }}" class="btn btn-primary" data-action="send">{{ __('requisitions.portal.send') }}</button>
                @endif
            @endif
            @if ($canCancel)<button type="button" wire:click="cancel" wire:confirm="{{ __('requisitions.portal.confirm_cancel') }}" class="btn btn-danger-outline" data-action="cancel">{{ __('requisitions.portal.cancel') }}</button>@endif
        </div>
    </section>

    <section class="card p-5" aria-labelledby="rq-files">
        <h3 id="rq-files" class="font-semibold text-ink">{{ __('requisitions.files') }}</h3>
        <ul class="mt-2 space-y-1 text-sm">
            @forelse ($requisition->attachments as $attachment)
                <li><a href="{{ route('portal.requisitions.attachment', [$requisition, $attachment]) }}" class="link" target="_blank" rel="noopener">{{ $attachment->original_name }}</a></li>
            @empty
                <li class="text-muted">—</li>
            @endforelse
        </ul>
        @if ($canEdit && $requisition->attachments->count() < 5)
            <form wire:submit="attach" class="mt-3 flex flex-wrap items-end gap-2">
                <label for="rq-file" class="sr-only">{{ __('requisitions.files') }}</label>
                <input wire:model="file" id="rq-file" type="file" class="form-input">
                <button type="submit" class="btn btn-secondary btn-sm">{{ __('requisitions.portal.attach') }}</button>
                @error('file') <p class="form-error w-full">{{ $message }}</p> @enderror
            </form>
        @endif
    </section>

    <section class="card" aria-labelledby="rq-comments">
        <h3 id="rq-comments" class="border-b border-line px-6 py-4 font-semibold text-ink">{{ __('requisitions.comments') }}</h3>
        <ol class="divide-y divide-line">
            @forelse ($comments as $comment)
                <li class="px-6 py-3 text-sm" wire:key="rc-{{ $comment->id }}">
                    <p class="text-xs text-muted">{{ $comment->user?->name }} · {{ $comment->created_at?->translatedFormat($dateTimeFormat) }} · {{ $comment->audience === 'shared' ? __('requisitions.audience.shared') : __('requisitions.audience.client') }}</p>
                    <p class="mt-1 whitespace-pre-line text-ink">{{ $comment->body }}</p>
                </li>
            @empty
                <li class="px-6 py-3 text-sm text-muted">{{ __('requisitions.no_comments') }}</li>
            @endforelse
        </ol>
        <form wire:submit="addComment" class="space-y-2 border-t border-line p-5" novalidate>
            <label for="rq-comment" class="sr-only">{{ __('requisitions.comments') }}</label>
            <textarea wire:model="comment" id="rq-comment" rows="2" class="form-input"></textarea>
            @error('comment') <p class="form-error">{{ $message }}</p> @enderror
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($requisition->status->isWithSupplier())
                    <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="commentShared" type="checkbox" class="rounded border-line"> {{ __('requisitions.portal.share_with_supplier') }}</label>
                @else
                    <span class="text-xs text-muted">{{ __('requisitions.portal.internal_only') }}</span>
                @endif
                <button type="submit" class="btn btn-secondary btn-sm">{{ __('requisitions.portal.comment') }}</button>
            </div>
        </form>
    </section>
</div>
