<div class="space-y-6">
    @php
        $order = $this->order;
        $open = $this->open;
        $money = fn (int $minor): string => \App\Support\Money::of($minor, $order->currency)->format();
        $dateTime = \App\Enums\Locale::current()->dateTimeFormat();
        $isDraft = $open?->status === \App\Enums\OrderAmendmentStatus::Draft;
    @endphp

    <a href="{{ route('orders.show', $order) }}" wire:navigate class="link text-sm">&larr; {{ __('order_amendments.back', ['number' => $order->number]) }}</a>

    <x-flash />

    <header class="card p-6">
        <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ __('order_amendments.title', ['number' => $order->number]) }}</h2>
        <p class="mt-1 text-sm text-muted">{{ $order->customer?->name }} · {{ $order->status->label() }} · {{ __('order_amendments.revision') }} {{ $order->revision }} · {{ $money($order->gross_minor) }}</p>
        <p class="mt-2 text-sm text-muted">{{ __('order_amendments.intro') }}</p>
        @if ($order->addendum_pending)
            <p class="mt-3 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" role="status" data-addendum-pending>{{ __('order_amendments.addendum_pending') }}</p>
        @endif
    </header>

    @if ($open === null)
        @if ($this->canRequest())
            <form wire:submit="start" class="card flex flex-col gap-3 p-5 sm:flex-row sm:items-end" novalidate data-start-amendment>
                <div class="grow">
                    <label for="amend-reason" class="form-label">{{ __('order_amendments.fields.reason') }} <x-required-mark /></label>
                    <input wire:model="reason" id="amend-reason" type="text" class="form-input" maxlength="500">
                </div>
                <button type="submit" class="btn btn-primary">{{ __('order_amendments.start') }}</button>
            </form>
        @else
            <p class="text-sm text-muted">{{ __('order_amendments.cannot_amend') }}</p>
        @endif
    @else
        <section class="card space-y-4 p-5" data-open-amendment>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h3 class="font-semibold text-ink">#{{ $open->number }} · {{ $open->reason }}</h3>
                <x-order-amendment-status :status="$open->status" />
            </div>
            @if ($open->needs_approval)
                <p class="text-xs text-muted" data-needs-approval>{{ __('order_amendments.needs_approval', ['percent' => rtrim(rtrim(bcdiv((string) $open->required_discount_bp, '100', 2), '0'), '.')]) }}</p>
            @endif

            <ul class="divide-y divide-line text-sm">
                @forelse ($open->changes as $index => $change)
                    <li class="flex items-center justify-between gap-3 py-2" wire:key="chg-{{ $index }}">
                        <div>
                            <span class="font-medium text-ink">{{ __('order_amendments.types.'.$change['type']) }}</span>
                            <span class="block text-xs text-muted">{{ $change['label'] }} · {{ \App\Services\Orders\OrderAmendmentSummary::describe($change) }}</span>
                        </div>
                        @if ($isDraft)
                            <button type="button" wire:click="removeChange({{ $index }})" class="btn btn-secondary btn-sm">{{ __('order_amendments.remove') }}</button>
                        @endif
                    </li>
                @empty
                    <li class="py-3 text-muted">{{ __('order_amendments.no_changes') }}</li>
                @endforelse
            </ul>

            @if ($isDraft)
                <form wire:submit="addChange" class="grid gap-3 border-t border-line pt-4 sm:grid-cols-4" novalidate data-change-form>
                    <div>
                        <label for="chg-type" class="form-label">{{ __('order_amendments.fields.type') }}</label>
                        <select wire:model.live="type" id="chg-type" class="form-input">
                            @foreach ($types as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($type === 'add_line')
                        <div class="sm:col-span-2">
                            @if ($this->selectedItem)
                                <p class="form-label">{{ __('order_amendments.fields.item') }}</p>
                                <p class="text-sm text-ink" data-selected-item>{{ $this->selectedItem->sku }} {{ $this->selectedItem->name }} <button type="button" wire:click="$set('itemId', null)" class="link text-xs">{{ __('common.cancel') }}</button></p>
                            @else
                                <label for="chg-item" class="form-label">{{ __('order_amendments.fields.item') }}</label>
                                <input wire:model.live.debounce.300ms="itemSearch" id="chg-item" type="search" class="form-input" placeholder="{{ __('order_amendments.item_search') }}">
                                @if ($this->itemOptions->isNotEmpty())
                                    <ul class="mt-1 divide-y divide-line rounded-md border border-line text-sm">
                                        @foreach ($this->itemOptions as $option)
                                            <li wire:key="aio-{{ $option->id }}"><button type="button" wire:click="pickItem({{ $option->id }})" class="block w-full px-3 py-2 text-left hover:bg-brand-50">{{ $option->sku }} · {{ $option->name }}</button></li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endif
                        </div>
                    @else
                        <div class="sm:col-span-2">
                            <label for="chg-line" class="form-label">{{ __('order_amendments.fields.line') }}</label>
                            <select wire:model="lineId" id="chg-line" class="form-input">
                                <option value="">—</option>
                                @foreach ($order->lines as $line)
                                    <option value="{{ $line->id }}">{{ $line->position }}. {{ $line->name }} ({{ \App\Support\Quantity::trimmed((string) $line->quantity) }}@if ((float) $line->cancelled_qty > 0), −{{ \App\Support\Quantity::trimmed((string) $line->cancelled_qty) }}@endif)</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if (in_array($type, ['qty_change', 'add_line', 'close_short'], true))
                        <div>
                            <label for="chg-qty" class="form-label">{{ __('order_amendments.quantity_label.'.$type) }}</label>
                            <input wire:model="quantity" id="chg-qty" type="text" inputmode="decimal" class="form-input">
                        </div>
                    @endif
                    @if (in_array($type, ['price_change', 'add_line'], true))
                        <div>
                            <label for="chg-discount" class="form-label">{{ __('order_amendments.fields.discount') }}</label>
                            <input wire:model="discount" id="chg-discount" type="text" inputmode="decimal" class="form-input" placeholder="0">
                        </div>
                    @endif
                    <div class="flex items-end justify-end sm:col-span-4"><button type="submit" class="btn btn-primary">{{ __('order_amendments.add_change') }}</button></div>
                </form>
            @endif

            <div class="flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:items-end" data-amendment-moves>
                @if ($isDraft && $this->canRequest())
                    <button type="button" wire:click="submit" wire:loading.attr="disabled" class="btn btn-primary" data-action="submit">{{ __('order_amendments.submit') }}</button>
                @endif
                @if ($open->status === \App\Enums\OrderAmendmentStatus::PendingApproval)
                    @if ($this->canReject() || $open->requested_by === auth()->id())
                        <div class="grow">
                            <label for="amend-note" class="form-label">{{ __('order_amendments.move_note') }}</label>
                            <input wire:model="moveNote" id="amend-note" type="text" class="form-input" maxlength="500">
                        </div>
                    @endif
                    @if ($this->canDecide())
                        <button type="button" wire:click="approve" class="btn btn-primary" data-action="approve">{{ __('order_amendments.approve') }}</button>
                    @endif
                    @if ($this->canReject())
                        <button type="button" wire:click="reject" class="btn btn-secondary" data-action="reject">{{ __('order_amendments.reject') }}</button>
                    @elseif ($open->requested_by === auth()->id())
                        <p class="text-sm text-muted" data-own-amendment>{{ __('order_amendments.own_hint') }}</p>
                    @endif
                @endif
                @if ($open->requested_by === auth()->id() || $this->canReject())
                    @if ($isDraft)
                        <div class="grow sm:max-w-xs">
                            <label for="amend-note" class="form-label">{{ __('order_amendments.move_note') }}</label>
                            <input wire:model="moveNote" id="amend-note" type="text" class="form-input" maxlength="500">
                        </div>
                    @endif
                    <button type="button" wire:click="cancel" wire:confirm="{{ __('order_amendments.cancel_confirm') }}" class="btn btn-danger-outline" data-action="cancel">{{ __('order_amendments.cancel') }}</button>
                @endif
            </div>
        </section>
    @endif

    <section class="card p-5" data-amendment-history>
        <h3 class="font-semibold text-ink">{{ __('order_amendments.history') }}</h3>
        <ol class="mt-2 divide-y divide-line">
            @forelse ($order->amendments->reject(fn ($amendment) => $amendment->status->isOpen()) as $amendment)
                <li class="space-y-1 py-3 text-sm" wire:key="am-{{ $amendment->id }}">
                    <p class="flex flex-wrap items-center gap-2"><span class="font-semibold text-ink">#{{ $amendment->number }}</span> <x-order-amendment-status :status="$amendment->status" /> <span class="text-muted">{{ $amendment->requester?->name }} · {{ ($amendment->applied_at ?? $amendment->updated_at)?->translatedFormat($dateTime) }}</span></p>
                    <p class="text-ink">{{ $amendment->reason }}</p>
                    <ul class="list-disc ps-5 text-xs text-muted">
                        @foreach ($amendment->changes as $change)
                            <li>{{ __('order_amendments.types.'.$change['type']) }}: {{ $change['label'] }} · {{ \App\Services\Orders\OrderAmendmentSummary::describe($change) }}</li>
                        @endforeach
                    </ul>
                    @if ($amendment->totals_before && $amendment->totals_after)
                        <p class="text-xs text-muted">{{ $money($amendment->totals_before['gross_minor']) }} → {{ $money($amendment->totals_after['gross_minor']) }} · {{ __('order_amendments.revision') }} {{ $amendment->revision_from }} → {{ $amendment->revision_to }}</p>
                    @endif
                    @if ($amendment->decision_note)
                        <p class="text-xs text-muted">{{ $amendment->decider?->name }}: {{ $amendment->decision_note }}</p>
                    @endif
                    @if ($amendment->status === \App\Enums\OrderAmendmentStatus::Applied && $amendment->addendum === null && $order->agreement_required && ($amendment->totals_before['gross_minor'] ?? 0) !== ($amendment->totals_after['gross_minor'] ?? 0))
                        <p class="text-xs text-muted" data-addendum-missing>{{ __('order_amendments.addendum_missing') }}</p>
                    @endif
                    @if ($amendment->addendum)
                        <p class="text-xs"><a href="{{ route('agreements.show', $amendment->addendum) }}" wire:navigate class="link" data-addendum-link>{{ __('order_amendments.addendum') }} {{ $amendment->addendum->number }}</a></p>
                    @endif
                </li>
            @empty
                <li class="py-3 text-muted">{{ __('order_amendments.empty') }}</li>
            @endforelse
        </ol>
    </section>
</div>
