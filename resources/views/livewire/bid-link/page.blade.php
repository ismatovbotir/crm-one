<div class="space-y-6" data-bid-link-page>
    @php
        $tender = $this->tender;
        $link = $this->link;
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $local = fn ($moment) => $tender->localTime($moment)?->translatedFormat($dateTimeFormat);
        $current = $this->myBids->first(fn ($bid) => $bid->status === \App\Enums\BidStatus::Submitted);
    @endphp

    <header class="card p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-muted">{{ $company }}</p>
                <p class="font-mono text-sm text-muted">{{ $tender->number }}</p>
                <h1 class="text-2xl font-semibold tracking-tight text-ink">{{ $tender->title }}</h1>
                <p class="mt-2 text-sm text-ink">{{ __('bid_link.for', ['name' => $link->invitation->counterparty?->name]) }}</p>
            </div>
            <div class="flex flex-col items-start gap-2 sm:items-end">
                <div class="inline-flex overflow-hidden rounded-md border border-line-strong" role="group" aria-label="{{ __('common.language') }}">
                    @foreach (\App\Enums\Locale::available() as $locale)
                        <a href="{{ $pageUrl }}?lang={{ $locale->value }}" hreflang="{{ $locale->value }}" @if ($locale === \App\Enums\Locale::current()) aria-current="true" @endif
                           @class(['px-3 py-1.5 text-xs font-semibold', 'border-l border-line-strong' => ! $loop->first, 'bg-brand-600 text-white' => $locale === \App\Enums\Locale::current(), 'bg-surface text-ink hover:bg-neutral-100' => $locale !== \App\Enums\Locale::current()])>{{ $locale->shortCode() }}</a>
                    @endforeach
                </div>
                <p class="text-sm text-ink" data-deadline>{{ __('bid_link.deadline', ['at' => ($deadline?->translatedFormat($dateTimeFormat) ?? '—').' '.$tz]) }}</p>
                @if ($canSubmit && $deadline)
                    <p class="text-xs text-muted">{{ __('bid_link.time_left', ['time' => now()->diffForHumans($tender->bids_due_at, ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2])]) }}</p>
                @endif
            </div>
        </div>
    </header>

    <x-flash />

    @if ($link->invitation->status === \App\Enums\InvitationStatus::Declined)
        <p class="card p-4 text-sm text-ink" data-declined>{{ __('bid_link.you_declined') }}</p>
    @endif

    <section class="card p-5" aria-labelledby="notice-title">
        <h2 id="notice-title" class="font-semibold text-ink">{{ __('bid_link.notice') }}</h2>
        <div class="mt-3 overflow-x-auto">{{ $notice }}</div>
    </section>

    <section class="card p-5" aria-labelledby="mybids-title" data-my-bids>
        <h2 id="mybids-title" class="font-semibold text-ink">{{ __('bid_link.my_bids') }}</h2>
        <ul class="mt-2 divide-y divide-line">
            @forelse ($this->myBids as $bid)
                <li class="py-2 text-sm" wire:key="mb-{{ $bid->id }}">
                    <p class="text-ink"><span class="font-semibold">v{{ $bid->version }}</span> · {{ $bid->status->label() }} · {{ $local($bid->submitted_at) }} {{ $tz }}</p>
                    <p class="font-mono text-xs text-muted">{{ __('bid_link.receipt', ['hash' => $bid->payload_hash]) }}</p>
                    @foreach ($bid->lots as $bidLot)
                        <p class="text-xs text-ink">{{ __('tenders.lot_short', ['number' => $bidLot->lot->number]) }}: {{ \App\Support\Money::of($bidLot->total_minor, $bid->currency)->format() }}</p>
                    @endforeach
                </li>
            @empty
                <li class="py-2 text-sm text-muted">{{ __('bid_link.no_bids') }}</li>
            @endforelse
        </ul>
        @if ($current && $canSubmit)
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <div class="min-w-64 flex-1"><label for="wd" class="form-label">{{ __('bid_link.withdraw_reason') }}</label><input wire:model="withdrawReason" id="wd" type="text" class="form-input"></div>
                <button type="button" wire:click="withdraw" wire:confirm="{{ __('bid_link.confirm_withdraw') }}" class="btn btn-danger-outline">{{ __('bid_link.withdraw') }}</button>
            </div>
        @endif
    </section>

    @if ($canSubmit)
        @unless ($verified)
            <section class="card p-5" aria-labelledby="verify-title" data-verify>
                <h2 id="verify-title" class="font-semibold text-ink">{{ __('bid_link.verify_title') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('bid_link.verify_hint', ['email' => \App\Services\Tenders\BidLinks::mask((string) $link->invitation->contact_email)]) }}</p>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <button type="button" wire:click="sendCode" class="btn btn-secondary" data-action="send-code">{{ __('bid_link.send_code') }}</button>
                    <div><label for="otp" class="form-label">{{ __('bid_link.code') }}</label><input wire:model="code" id="otp" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-input w-32">@error('code') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <button type="button" wire:click="verify" class="btn btn-primary" data-action="verify">{{ __('bid_link.verify') }}</button>
                </div>
            </section>
        @else
            <form wire:submit="submit" class="card space-y-5 p-5" novalidate data-link-bid-form>
                <h2 class="font-semibold text-ink">{{ $current ? __('bid_link.revise') : __('bid_link.submit_title') }}</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="l-cur" class="form-label">{{ __('tenders.bids.currency') }}</label>
                        <select wire:model="form.currency" id="l-cur" class="form-input">@foreach ((array) $tender->accepted_currencies as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach</select>
                    </div>
                    <div><label for="l-valid" class="form-label">{{ __('bid_link.valid_until') }}</label><input wire:model="form.valid_until" id="l-valid" type="date" class="form-input">@error('form.valid_until') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="l-pdays" class="form-label">{{ __('tenders.bids.payment_days') }}</label><input wire:model="form.payment_days" id="l-pdays" type="number" min="0" class="form-input"></div>
                    <div><label for="l-prep" class="form-label">{{ __('tenders.bids.prepayment_percent') }}</label><input wire:model="form.prepayment_percent" id="l-prep" type="text" inputmode="decimal" class="form-input"></div>
                    <label class="flex items-center gap-2 pt-6 text-sm text-ink"><input wire:model.live="form.subcontracting" type="checkbox" class="rounded border-line"> {{ __('tenders.bids.subcontracting') }}</label>
                    @if ($form['subcontracting'])<div><label for="l-sub" class="form-label">{{ __('tenders.bids.subcontractor_name') }}</label><input wire:model="form.subcontractor_name" id="l-sub" type="text" class="form-input"></div>@endif
                </div>

                @foreach ($tender->lots as $lot)
                    <fieldset class="space-y-3 rounded-md border border-line p-3" wire:key="ll-{{ $lot->id }}">
                        <legend class="px-1 text-sm font-semibold text-ink">{{ __('tenders.lot_short', ['number' => $lot->number]) }} · {{ $lot->title }}</legend>
                        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="form.lots.{{ $lot->id }}.offered" type="checkbox" class="rounded border-line"> {{ __('tenders.bids.lot_offered') }}</label>
                        @if ($form['lots'][$lot->id]['offered'] ?? false)
                            <div class="grid gap-3 sm:grid-cols-3">
                                @if ($isTransport)
                                    <div><label for="l-transit-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.transit_days') }}</label><input wire:model="form.lots.{{ $lot->id }}.transit_days" id="l-transit-{{ $lot->id }}" type="number" min="0" class="form-input"></div>
                                    <div><label for="l-down-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.downtime_rate') }}</label><input wire:model="form.lots.{{ $lot->id }}.downtime_rate" id="l-down-{{ $lot->id }}" type="text" inputmode="decimal" class="form-input"></div>
                                    <div><label for="l-veh-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.vehicle_offered') }}</label><input wire:model="form.lots.{{ $lot->id }}.vehicle_offered" id="l-veh-{{ $lot->id }}" type="text" class="form-input"></div>
                                    <div class="sm:col-span-2"><label for="l-route-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.route') }}</label><input wire:model="form.lots.{{ $lot->id }}.route" id="l-route-{{ $lot->id }}" type="text" class="form-input"></div>
                                    <div><label for="l-border-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.border_crossing') }}</label><input wire:model="form.lots.{{ $lot->id }}.border_crossing" id="l-border-{{ $lot->id }}" type="text" class="form-input"></div>
                                @else
                                    <div><label for="l-lead-{{ $lot->id }}" class="form-label">{{ __('tenders.bids.lead_time_days') }}</label><input wire:model="form.lots.{{ $lot->id }}.lead_time_days" id="l-lead-{{ $lot->id }}" type="number" min="0" class="form-input"></div>
                                @endif
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <tbody class="divide-y divide-line">
                                        @if ($isTransport)
                                            @foreach ($chargeCodes as $codeValue => $label)
                                                <tr wire:key="lc-{{ $lot->id }}-{{ $codeValue }}">
                                                    <td class="py-1 pr-2 text-ink">{{ $label }}</td>
                                                    <td class="py-1 pr-2"><label for="li-{{ $lot->id }}-{{ $codeValue }}" class="sr-only">{{ __('tenders.bids.inclusion') }}</label><select wire:model="form.lots.{{ $lot->id }}.lines.{{ $codeValue }}.inclusion" id="li-{{ $lot->id }}-{{ $codeValue }}" class="form-input"><option value="">—</option>@foreach ($inclusions as $value => $text)<option value="{{ $value }}">{{ $text }}</option>@endforeach</select></td>
                                                    <td class="py-1 pr-2"><label for="la-{{ $lot->id }}-{{ $codeValue }}" class="sr-only">{{ __('tenders.bids.amount') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $codeValue }}.amount" id="la-{{ $lot->id }}-{{ $codeValue }}" type="text" inputmode="decimal" class="form-input w-36" placeholder="{{ __('tenders.bids.amount') }}"></td>
                                                    <td class="py-1"><label for="lb-{{ $lot->id }}-{{ $codeValue }}" class="sr-only">{{ __('tenders.bids.basis') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $codeValue }}.{{ $codeValue === 'other' ? 'description' : 'basis' }}" id="lb-{{ $lot->id }}-{{ $codeValue }}" type="text" class="form-input" placeholder="{{ $codeValue === 'other' ? __('tenders.bids.description') : __('tenders.bids.basis_placeholder') }}"></td>
                                                </tr>
                                            @endforeach
                                        @else
                                            @foreach ($lot->items as $item)
                                                <tr wire:key="lp-{{ $item->id }}">
                                                    <td class="py-1 pr-2 text-ink">{{ $item->name }} <span class="block text-xs text-muted">{{ \App\Support\Quantity::trimmed((string) $item->quantity) }} {{ $item->unit_code }}</span></td>
                                                    <td class="py-1 pr-2"><label for="lu-{{ $item->id }}" class="sr-only">{{ __('tenders.bids.unit_price') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.unit_price" id="lu-{{ $item->id }}" type="text" inputmode="decimal" class="form-input w-32" placeholder="{{ __('tenders.bids.unit_price') }}"></td>
                                                    <td class="py-1 pr-2"><label for="lbr-{{ $item->id }}" class="sr-only">{{ __('tenders.bids.offered') }}</label><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.offered_brand" id="lbr-{{ $item->id }}" type="text" class="form-input" placeholder="{{ __('tenders.bids.brand_model') }}"></td>
                                                    <td class="py-1"><label class="flex items-center gap-2 text-sm text-ink"><input wire:model="form.lots.{{ $lot->id }}.lines.{{ $item->id }}.compliant" type="checkbox" class="rounded border-line"> {{ __('tenders.bids.compliant') }}</label></td>
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
                    <div><label for="l-excl" class="form-label">{{ __('tenders.bids.exclusions') }}</label><textarea wire:model="form.exclusions" id="l-excl" rows="2" class="form-input"></textarea></div>
                    <div><label for="l-notes" class="form-label">{{ __('tenders.bids.notes') }}</label><textarea wire:model="form.notes" id="l-notes" rows="2" class="form-input"></textarea></div>
                    <div class="sm:col-span-2"><label for="l-files" class="form-label">{{ __('bid_link.files') }}</label><input wire:model="files" id="l-files" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="form-input">@error('files.*') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2"><input wire:model="form.conditions_accepted" type="checkbox" class="rounded border-line"> {{ __('bid_link.conditions') }}</label>
                    @error('form.conditions_accepted') <p class="form-error sm:col-span-2">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled" data-action="submit-link-bid">{{ __('bid_link.submit') }}</button></div>
            </form>
        @endunless

        <section class="card p-5" aria-labelledby="ask-title">
            <h2 id="ask-title" class="font-semibold text-ink">{{ __('bid_link.ask_title') }}</h2>
            <p class="mt-1 text-sm text-muted">{{ __('bid_link.ask_hint') }}</p>
            <form wire:submit="ask" class="mt-3 space-y-2">
                <label for="q" class="sr-only">{{ __('bid_link.question') }}</label>
                <textarea wire:model="question" id="q" rows="2" class="form-input"></textarea>
                @error('question') <p class="form-error">{{ $message }}</p> @enderror
                <div class="flex justify-end"><button type="submit" class="btn btn-secondary">{{ __('bid_link.ask') }}</button></div>
            </form>
        </section>

        @unless ($current)
            <section class="card p-5" aria-labelledby="decline-title">
                <h2 id="decline-title" class="font-semibold text-ink">{{ __('bid_link.decline_title') }}</h2>
                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <div class="min-w-64 flex-1"><label for="dr" class="form-label">{{ __('bid_link.decline_reason') }}</label><input wire:model="declineReason" id="dr" type="text" class="form-input"></div>
                    <button type="button" wire:click="decline" wire:confirm="{{ __('bid_link.confirm_decline') }}" class="btn btn-secondary">{{ __('bid_link.decline') }}</button>
                </div>
            </section>
        @endunless
    @else
        <p class="card p-4 text-sm text-ink" data-closed>{{ __('bid_link.closed') }}</p>
    @endif
</div>
