<div class="space-y-6" data-bid-board>
    @php
        $tender = $this->tender;
        $user = auth()->user();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $local = fn ($moment) => $tender->localTime($moment)?->translatedFormat($dateTimeFormat);
    @endphp

    @unless ($tender->isOpened())
        <p class="rounded-md border border-line bg-neutral-50 px-3 py-2 text-sm text-ink" data-sealed-note><x-icon name="lock" class="inline size-4" /> {{ __('tenders.bids.sealed_note', ['at' => ($local($tender->bids_due_at) ?? '—').' '.$tz]) }}</p>
    @endunless

    @can('enterBid', $tender)
        @unless ($entering)
            <div class="flex justify-end"><button type="button" wire:click="startEntry" class="btn btn-primary" data-action="enter-bid"><x-icon name="plus" class="size-4" /> {{ __('tenders.bids.enter') }}</button></div>
        @endunless
    @endcan

    @if ($entering)
        <form wire:submit="submit" class="space-y-5 rounded-md border-2 border-brand-600 p-4" novalidate data-bid-form>
            <h3 class="font-semibold text-ink">{{ __('tenders.bids.enter') }}</h3>
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label for="b-inv" class="form-label">{{ __('tenders.bids.counterparty') }}</label>
                    <select wire:model="form.invitation_id" id="b-inv" class="form-input">
                        <option value="">—</option>
                        @foreach ($tender->invitations as $invitation) <option value="{{ $invitation->id }}">{{ $invitation->counterparty?->name }}</option> @endforeach
                    </select>
                    @error('form.invitation_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="b-cur" class="form-label">{{ __('tenders.bids.currency') }}</label>
                    <select wire:model="form.currency" id="b-cur" class="form-input">
                        @foreach ((array) $tender->accepted_currencies as $currency) <option value="{{ $currency }}">{{ $currency }}</option> @endforeach
                    </select>
                </div>
                <div><label for="b-valid" class="form-label">{{ __('tenders.bids.valid_until') }}</label><input wire:model="form.valid_until" id="b-valid" type="date" class="form-input"></div>
                <div><label for="b-pdays" class="form-label">{{ __('tenders.bids.payment_days') }}</label><input wire:model="form.payment_days" id="b-pdays" type="number" min="0" class="form-input"></div>
                <div><label for="b-prep" class="form-label">{{ __('tenders.bids.prepayment_percent') }}</label><input wire:model="form.prepayment_percent" id="b-prep" type="text" inputmode="decimal" class="form-input"></div>
                <div class="flex items-end gap-3">
                    <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="form.subcontracting" type="checkbox" class="rounded border-line"> {{ __('tenders.bids.subcontracting') }}</label>
                </div>
                @if ($form['subcontracting'])
                    <div class="sm:col-span-3"><label for="b-sub" class="form-label">{{ __('tenders.bids.subcontractor_name') }}</label><input wire:model="form.subcontractor_name" id="b-sub" type="text" class="form-input"></div>
                @endif
            </div>

            @foreach ($tender->lots as $lot)
                <fieldset class="space-y-3 rounded-md border border-line p-3" wire:key="bl-{{ $lot->id }}">
                    <legend class="px-1 text-sm font-semibold text-ink">{{ __('tenders.lot_short', ['number' => $lot->number]) }} · {{ $lot->title }}</legend>
                    <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="form.lots.{{ $lot->id }}.offered" type="checkbox" class="rounded border-line"> {{ __('tenders.bids.lot_offered') }}</label>
                    @if ($form['lots'][$lot->id]['offered'] ?? false)
                        <div class="grid gap-3 sm:grid-cols-3">
                            @if ($isTransport)
                                <div><label for="b-transit-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.transit_days') }}</label><input wire:model="form.lots.{{ $lot->id }}.transit_days" id="b-transit-{{ $lot->id }}" type="number" min="0" class="form-input"></div>
                                <div><label for="b-down-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.downtime_rate') }}</label><input wire:model="form.lots.{{ $lot->id }}.downtime_rate" id="b-down-{{ $lot->id }}" type="text" inputmode="decimal" class="form-input"></div>
                                <div><label for="b-veh-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.vehicle_offered') }}</label><input wire:model="form.lots.{{ $lot->id }}.vehicle_offered" id="b-veh-{{ $lot->id }}" type="text" class="form-input"></div>
                                <div class="sm:col-span-2"><label for="b-route-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.route') }}</label><input wire:model="form.lots.{{ $lot->id }}.route" id="b-route-{{ $lot->id }}" type="text" class="form-input"></div>
                                <div><label for="b-border-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.border_crossing') }}</label><input wire:model="form.lots.{{ $lot->id }}.border_crossing" id="b-border-{{ $lot->id }}" type="text" class="form-input"></div>
                            @else
                                <div><label for="b-lead-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.lead_time_days') }}</label><input wire:model="form.lots.{{ $lot->id }}.lead_time_days" id="b-lead-{{ $lot->id }}" type="number" min="0" class="form-input"></div>
                            @endif
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                                    @if ($isTransport)
                                        <tr><th scope="col" class="py-1">{{ __('tenders.bids.charge') }}</th><th scope="col" class="py-1">{{ __('tenders.bids.inclusion') }}</th><th scope="col" class="py-1">{{ __('tenders.bids.amount') }}</th><th scope="col" class="py-1">{{ __('tenders.bids.basis') }}</th></tr>
                                    @else
                                        <tr><th scope="col" class="py-1">{{ __('tenders.items.name') }}</th><th scope="col" class="py-1">{{ __('tenders.bids.unit_price') }}</th><th scope="col" class="py-1">{{ __('tenders.items.quantity') }}</th><th scope="col" class="py-1">{{ __('tenders.bids.offered') }}</th><th scope="col" class="py-1">{{ __('tenders.bids.compliant') }}</th></tr>
                                    @endif
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @if ($isTransport)
                                        @foreach ($chargeCodes as $code => $label)
                                            <tr wire:key="ch-{{ $lot->id }}-{{ $code }}">
                                                <td class="py-1 pr-2 text-ink">{{ $label }}</td>
                                                <td class="py-1 pr-2"><label for="inc-{{ $lot->id }}-{{ $code }}" class="sr-only">{{ __('tenders.bids.inclusion') }}</label>
                                                    <select wire:model="form.lots.{{ $lot->id }}.lines.{{ $code }}.inclusion" id="inc-{{ $lot->id }}-{{ $code }}" class="form-input"><option value="">—</option>@foreach ($inclusions as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach</select></td>
                                                <td class="py-1 pr-2"><label for="amt-{{ $lot->id }}-{{ $code }}" class="sr-only">{{ __('tenders.bids.amount') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $code }}.amount" id="amt-{{ $lot->id }}-{{ $code }}" type="text" inputmode="decimal" class="form-input w-36"></td>
                                                <td class="py-1"><label for="bas-{{ $lot->id }}-{{ $code }}" class="sr-only">{{ __('tenders.bids.basis') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $code }}.{{ $code === 'other' ? 'description' : 'basis' }}" id="bas-{{ $lot->id }}-{{ $code }}" type="text" class="form-input" placeholder="{{ $code === 'other' ? __('tenders.bids.description') : __('tenders.bids.basis_placeholder') }}"></td>
                                            </tr>
                                        @endforeach
                                    @else
                                        @foreach ($lot->items as $item)
                                            <tr wire:key="pl-{{ $item->id }}" class="align-top">
                                                <td class="py-1 pr-2 text-ink">{{ $item->name }} <span class="block text-xs text-muted">{{ \App\Support\Quantity::trimmed((string) $item->quantity) }} {{ $item->unit_code }}</span></td>
                                                <td class="py-1 pr-2"><label for="up-{{ $item->id }}" class="sr-only">{{ __('tenders.bids.unit_price') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.unit_price" id="up-{{ $item->id }}" type="text" inputmode="decimal" class="form-input w-32"></td>
                                                <td class="py-1 pr-2"><label for="q-{{ $item->id }}" class="sr-only">{{ __('tenders.items.quantity') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.quantity" id="q-{{ $item->id }}" type="text" inputmode="decimal" class="form-input w-24" placeholder="{{ \App\Support\Quantity::trimmed((string) $item->quantity) }}"></td>
                                                <td class="py-1 pr-2"><label for="br-{{ $item->id }}" class="sr-only">{{ __('tenders.bids.offered') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.offered_brand" id="br-{{ $item->id }}" type="text" class="form-input" placeholder="{{ __('tenders.bids.brand_model') }}"></td>
                                                <td class="py-1"><label class="flex items-center gap-2 text-sm text-ink"><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.compliant" type="checkbox" class="rounded border-line"> {{ __('common.yes') }}</label></td>
                                            </tr>
                                        @endforeach
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    @endif
                </fieldset>
            @endforeach

            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="b-excl" class="form-label">{{ __('tenders.bids.exclusions') }}</label><textarea wire:model="form.exclusions" id="b-excl" rows="2" class="form-input"></textarea></div>
                <div><label for="b-notes" class="form-label">{{ __('tenders.bids.notes') }}</label><textarea wire:model="form.notes" id="b-notes" rows="2" class="form-input"></textarea></div>
                <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2"><input wire:model="form.conditions_accepted" type="checkbox" class="rounded border-line"> {{ __('tenders.bids.conditions_accepted') }}</label>
            </div>

            <fieldset class="grid gap-3 rounded-md border border-line p-3 sm:grid-cols-3" data-evidence>
                <legend class="px-1 text-sm font-semibold text-ink">{{ __('tenders.bids.evidence') }}</legend>
                <div>
                    <label for="b-via" class="form-label">{{ __('tenders.bids.received_via') }}</label>
                    <select wire:model="form.source.received_via" id="b-via" class="form-input">
                        @foreach (config('tenders.vocab.received_via') as $via) <option value="{{ $via }}">{{ __('tenders.vocab.received_via.'.$via) }}</option> @endforeach
                    </select>
                </div>
                <div><label for="b-at" class="form-label">{{ __('tenders.bids.received_at') }} ({{ $tz }})</label><input wire:model="form.source.received_at" id="b-at" type="datetime-local" class="form-input">@error('form.source.received_at') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="b-sender" class="form-label">{{ __('tenders.bids.sender_name') }}</label><input wire:model="form.source.sender_name" id="b-sender" type="text" class="form-input">@error('form.source.sender_name') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="b-contact" class="form-label">{{ __('tenders.bids.sender_contact') }}</label><input wire:model="form.source.sender_contact" id="b-contact" type="text" class="form-input"></div>
                <div class="sm:col-span-2"><label for="b-note" class="form-label">{{ __('tenders.bids.evidence_note') }}</label><textarea wire:model="form.source.note" id="b-note" rows="2" class="form-input"></textarea></div>
                <div class="sm:col-span-3"><label for="b-files" class="form-label">{{ __('tenders.bids.evidence_files') }}</label><input wire:model="evidence" id="b-files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="form-input">@error('evidence.*') <p class="form-error">{{ $message }}</p> @enderror @error('evidence') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-muted">{{ __('tenders.bids.evidence_hint', ['min' => (int) config('tenders.evidence_note_min')]) }}</p></div>
            </fieldset>

            <div><label for="b-corr" class="form-label">{{ __('tenders.bids.correction_reason') }}</label><input wire:model="form.correction_reason" id="b-corr" type="text" class="form-input" placeholder="{{ __('tenders.bids.correction_hint') }}"></div>

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('entering', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('tenders.bids.submit') }}</button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm" data-bids>
            <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                <tr><th scope="col" class="py-2">{{ __('tenders.bids.counterparty') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.version') }}</th><th scope="col" class="py-2">{{ __('common.status') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.submitted_at') }} ({{ $tz }})</th><th scope="col" class="py-2">{{ __('tenders.bids.channel') }}</th><th scope="col" class="py-2">{{ __('tenders.bids.content') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($this->bids as $bid)
                    @php($canSee = $user->can('viewContent', $bid))
                    <tr wire:key="bid-{{ $bid->id }}" class="align-top" data-bid="{{ $bid->id }}">
                        <td class="py-2 text-ink">{{ $bid->counterparty?->name }}@if ($bid->needs_confirmation) <x-badge tone="silver" icon="warning">{{ __('tenders.invitees.needs_confirmation') }}</x-badge>@endif</td>
                        <td class="py-2 text-ink">{{ $bid->round_no > 1 ? __('tenders.round', ['round' => $bid->round_no]).' · ' : '' }}v{{ $bid->version }}</td>
                        <td class="py-2">@php([$tone, $icon] = $bid->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $bid->status->label() }}</x-badge>@if ($bid->disqualification_reason)<span class="block text-xs text-muted">{{ $bid->disqualification_reason }}</span>@endif</td>
                        <td class="py-2 whitespace-nowrap text-ink">{{ $local($bid->submitted_at) }}<span class="block font-mono text-[11px] text-muted" title="sha256">{{ \Illuminate\Support\Str::limit((string) $bid->payload_hash, 16, '…') }}</span></td>
                        <td class="py-2 text-ink">{{ $bid->channel->label() }}@if ($bid->enteredBy)<span class="block text-xs text-muted">{{ $bid->enteredBy->name }}</span>@endif</td>
                        <td class="py-2">
                            @if ($canSee)
                                @php($bid->load(['lots.lines.tenderItem', 'lots.lot', 'source', 'attachments']))
                                <div class="space-y-2" data-bid-content>
                                    @foreach ($bid->lots as $bidLot)
                                        <div wire:key="blc-{{ $bidLot->id }}">
                                            <p class="font-semibold text-ink">{{ __('tenders.lot_short', ['number' => $bidLot->lot->number]) }}: {{ \App\Support\Money::of($bidLot->total_minor, $bid->currency)->format() }}
                                                @if ($bidLot->transit_days !== null) · {{ trans_choice('tenders.bids.days', $bidLot->transit_days, ['count' => $bidLot->transit_days]) }}@endif
                                                @if ($bidLot->lead_time_days !== null) · {{ trans_choice('tenders.bids.days', $bidLot->lead_time_days, ['count' => $bidLot->lead_time_days]) }}@endif</p>
                                            <ul class="text-xs text-muted">
                                                @foreach ($bidLot->lines as $line)
                                                    <li>{{ $line->charge_code?->label() ?? $line->tenderItem?->name }}: {{ $line->inclusion->value === 'included' ? \App\Support\Money::of($line->amount_minor, $bid->currency)->format() : $line->inclusion->label() }}@if ($line->offered_brand) · {{ $line->offered_brand }}@endif</li>
                                                @endforeach
                                                @if ($bidLot->downtime_rate_minor_per_day !== null)<li>{{ __('tenders.bids.downtime_rate') }}: {{ \App\Support\Money::of($bidLot->downtime_rate_minor_per_day, $bid->currency)->format() }}</li>@endif
                                            </ul>
                                        </div>
                                    @endforeach
                                    @if ($bid->source)
                                        <p class="text-xs text-muted">{{ __('tenders.bids.source_line', ['via' => __('tenders.vocab.received_via.'.$bid->source->received_via), 'at' => $local($bid->source->received_at), 'sender' => $bid->source->sender_name]) }}@if ($bid->source->note) · {{ $bid->source->note }}@endif</p>
                                    @endif
                                    @foreach ($bid->attachments as $attachment)
                                        <a href="{{ route('attachments.download', $attachment) }}" target="_blank" rel="noopener" class="link text-xs">{{ $attachment->original_name }}</a>
                                    @endforeach
                                </div>
                            @else
                                <span class="text-xs text-muted"><x-icon name="lock" class="inline size-3" /> {{ __('tenders.sealed') }}</span>
                            @endif
                            @can('withdraw', $bid)
                                @if ($withdrawBidId === $bid->id)
                                    <div class="mt-2 flex items-end gap-2">
                                        <label for="wd-reason" class="sr-only">{{ __('tenders.bids.withdraw_reason') }}</label>
                                        <input wire:model="withdrawReason" id="wd-reason" type="text" class="form-input" placeholder="{{ __('tenders.bids.withdraw_reason') }}">
                                        <button type="button" wire:click="withdraw" class="btn btn-danger-outline btn-sm">{{ __('tenders.bids.withdraw') }}</button>
                                    </div>
                                @else
                                    <button type="button" wire:click="$set('withdrawBidId', {{ $bid->id }})" class="btn btn-secondary btn-sm mt-2">{{ __('tenders.bids.withdraw') }}</button>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state :title="__('tenders.bids.empty')" icon="offers" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
