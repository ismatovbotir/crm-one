<div class="space-y-6">
    @php
        $order = $this->order;
        $user = auth()->user();
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $agreement = $this->agreement;
    @endphp

    <a href="{{ route('orders.index') }}" wire:navigate class="link text-sm">&larr; {{ __('orders.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <p class="font-mono text-sm text-muted">{{ $order->number }}</p>
                <h2 class="text-2xl font-semibold tracking-tight break-words text-ink">
                    @can('view', $order->customer)
                        <a href="{{ route('customers.show', $order->customer) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $order->customer?->name }}</a>
                    @else
                        {{ $order->customer?->name }}
                    @endcan
                </h2>
                <div class="mt-3 flex flex-wrap gap-2">
                    <x-orders.status-badge :status="$order->status" />
                    <x-badge :tone="$order->sale_type->isRetail() ? 'dark' : 'outline'" data-sale-type="{{ $order->sale_type->value }}">{{ $order->sale_type->label() }}</x-badge>
                    @php
                        [$payTone, $payIcon] = $order->payment_status->badge();
                    @endphp
                    <x-badge :tone="$payTone" :icon="$payIcon">{{ $order->payment_status->label() }}</x-badge>
                    @if ($agreement)
                        <x-agreements.status-badge :status="$agreement->status" />
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap gap-2" data-order-actions>
                @can('confirm', $order)
                    <button type="button" wire:click="openPanel('confirm')" class="btn btn-primary" data-action="confirm">{{ __('orders.actions.confirm') }}</button>
                @endcan
                @can('startPackaging', $order)
                    <button type="button" wire:click="startPackaging" class="btn btn-primary" wire:loading.attr="disabled" data-action="start-packaging">{{ __('orders.actions.start_packaging') }}</button>
                @endcan
                @can('changeSaleType', $order)
                    <button type="button" wire:click="openPanel('sale_type')" class="btn btn-secondary" data-action="sale-type">{{ __('orders.actions.change_sale_type') }}</button>
                @endcan
                @can('updateDetails', $order)
                    <button type="button" wire:click="openPanel('details')" class="btn btn-secondary" data-action="details">{{ __('orders.actions.details') }}</button>
                @endcan
                @if (! in_array($order->status, [\App\Enums\OrderStatus::New, \App\Enums\OrderStatus::Cancelled, \App\Enums\OrderStatus::Closed], true))
                    @can('request', \App\Models\Tender::class)
                        <button type="button" wire:click="requestTransport" wire:confirm="{{ __('tenders.confirm_request') }}" class="btn btn-secondary" data-action="request-transport">{{ __('tenders.actions.request_transport') }}</button>
                    @endcan
                @endif
                @can('print', $order)
                    <a href="{{ route('orders.print', $order) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('orders.actions.print') }}</a>
                @endcan
                @can('amend', $order)
                    <a href="{{ route('orders.amendments', $order) }}" wire:navigate class="btn btn-secondary" data-action="amend">{{ __('orders.actions.amend') }}</a>
                @endcan
                @can('cancel', $order)
                    <button type="button" wire:click="openPanel('cancel')" class="btn btn-danger-outline" data-action="cancel">{{ __('orders.actions.cancel') }}</button>
                @endcan
            </div>
        </div>

        {{-- Summary (always visible above the tabs): key facts only; the rest is on the Header tab. --}}
        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4" data-document-summary>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.total') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $order->money($order->gross_minor)->format() }}</dd><dd class="text-xs text-muted">{{ __('orders.show.net_vat', ['net' => $order->money($order->net_minor)->format(), 'vat' => $order->money($order->vat_minor)->format()]) }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.owner') }}</dt><dd class="mt-1 text-ink">{{ $order->owner?->name }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.promised_delivery_on') }}</dt><dd class="mt-1 text-ink">{{ $order->promised_delivery_on?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.delivery_method') }}</dt><dd class="mt-1 text-ink" data-delivery-method="{{ $order->delivery_method->value }}">{{ $order->delivery_method->label() }}@if ($order->warehouse) · {{ $order->warehouse->name }}@endif</dd></div>
        </dl>

        @if ($order->hold_reasons)
            <ul class="mt-4 space-y-2" data-holds>
                @foreach ($order->hold_reasons as $hold)
                    @php
                        $reason = \App\Enums\HoldReason::tryFrom($hold['reason']);
                    @endphp
                    @continue($reason === null)
                    <li @class(['flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm', 'border-brand-600 bg-brand-50 text-brand-800' => empty($hold['overridden_at']), 'border-line bg-neutral-50 text-ink' => ! empty($hold['overridden_at'])]) wire:key="hold-{{ $hold['reason'] }}" data-hold="{{ $hold['reason'] }}">
                        <span class="flex items-center gap-2">
                            <x-icon :name="empty($hold['overridden_at']) ? 'lock' : 'check'" class="size-4" />
                            <span class="font-semibold">{{ $reason->label() }}</span>
                            @if (! empty($hold['overridden_at']))
                                <span>· {{ __('orders.show.released_by', ['user' => $this->holdUsers[$hold['overridden_by']] ?? __('activity.system'), 'reason' => $hold['override_reason']]) }}</span>
                            @else
                                <span>· {{ __('orders.hold_help.'.$hold['reason']) }}</span>
                            @endif
                        </span>
                        @if (empty($hold['overridden_at']))
                            @can('overrideHold', [$order, $reason])
                                <button type="button" wire:click="openPanel('override', '{{ $hold['reason'] }}')" class="btn btn-secondary btn-sm" data-action="override">{{ __('orders.actions.release_hold') }}</button>
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </header>

    @php
        $flow = $this->flow;
    @endphp
    <section class="card p-5" aria-labelledby="flow-title" data-order-flow>
        <h3 id="flow-title" class="sr-only">{{ __('orders.flow.title') }}</h3>
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-3 text-sm">
            @foreach ($flow['steps'] as $index => $step)
                <li class="flex items-center gap-2" wire:key="step-{{ $step['key'] }}" data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}" @if ($step['state'] === 'current') aria-current="step" @endif>
                    @if ($index > 0) <span class="h-px w-4 bg-line-strong" aria-hidden="true"></span> @endif
                    <span @class([
                        'inline-flex size-6 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-ink text-white' => $step['state'] === 'done',
                        'bg-brand-600 text-white' => $step['state'] === 'current',
                        'border-2 border-brand-600 text-brand-700' => $step['state'] === 'blocked',
                        'border border-line-strong text-muted' => $step['state'] === 'upcoming',
                    ])>
                        @if ($step['state'] === 'done') <x-icon name="check" class="size-3.5" /> @elseif ($step['state'] === 'blocked') <x-icon name="warning" class="size-3.5" /> @else {{ $index + 1 }} @endif
                    </span>
                    <span @class(['font-semibold text-ink' => in_array($step['state'], ['current', 'blocked'], true), 'text-ink' => $step['state'] === 'done', 'text-muted' => $step['state'] === 'upcoming'])>{{ __('orders.flow.steps.'.$step['key']) }}</span>
                    <span class="sr-only">({{ __('orders.flow.states.'.$step['state']) }})</span>
                </li>
            @endforeach
        </ol>
        @if ($flow['next'])
            <p class="mt-4 flex items-start gap-2 border-t border-line pt-4 text-sm text-ink" data-next-action="{{ $flow['next'] }}">
                <x-icon name="clock" class="mt-0.5 size-4 shrink-0 text-brand-700" />
                <span><span class="font-semibold">{{ __('orders.flow.next_label') }}</span> {{ __('orders.flow.next.'.$flow['next']) }}</span>
                @if ($tab !== 'other' && in_array($flow['next'], ['agreement', 'pay_full', 'pay_prepayment', 'create_packing_list', 'post_delivery', 'tax_invoice', 'collect_payment', 'issue_receipt'], true))
                    {{-- The action for this step lives on the Other tab (payments, deliveries, agreement). --}}
                    <button type="button" wire:click="$set('tab', 'other')" class="btn btn-secondary btn-sm ml-auto shrink-0" data-go-to-tab="other">{{ __('common.document_tabs.open_tab', ['tab' => __('common.document_tabs.other')]) }}</button>
                @endif
            </p>
        @endif
    </section>

    @if (! $order->sale_type->isRetail())
        <livewire:orders.power-of-attorney :order-id="$order->id" :key="'poa-'.$order->id.'-'.$order->updated_at?->timestamp" />
    @endif

    @if ($panel !== '')
        <section class="card border-2 border-brand-600 p-5" role="dialog" aria-labelledby="panel-title" data-panel="{{ $panel }}">
            <h3 id="panel-title" class="font-semibold text-ink">{{ __('orders.panels.'.$panel) }}</h3>

            @if ($panel === 'confirm')
                @php
                    $preview = $this->confirmPreview;
                @endphp
                @if ($preview['stale'] !== null)
                    <p class="mt-3 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-stock-stale>{{ $preview['stale'] >= 0 ? __('orders.confirm.stock_stale', ['minutes' => $preview['stale']]) : __('orders.confirm.stock_never') }}</p>
                @endif
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs text-muted uppercase"><tr><th class="py-1 pr-3">{{ __('orders.lines.name') }}</th><th class="py-1 pr-3 text-right">{{ __('orders.lines.quantity') }}</th><th class="py-1 text-right">{{ __('orders.confirm.available') }}</th></tr></thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($order->lines as $line)
                                <tr wire:key="cf-{{ $line->id }}" @class(['text-brand-800 font-semibold' => isset($preview['shortages'][$line->id])])>
                                    <td class="py-1 pr-3">{{ $line->name }}</td>
                                    <td class="py-1 pr-3 text-right">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit_name }}</td>
                                    <td class="py-1 text-right">
                                        @if ($line->item?->type === \App\Enums\ItemType::Service)
                                            <span class="text-muted">{{ __('orders.confirm.service') }}</span>
                                        @else
                                            {{ \App\Support\Quantity::trimmed($preview['available'][$line->id] ?? '0') }}
                                            @isset($preview['shortages'][$line->id]) <span class="block text-xs">{{ __('orders.confirm.short') }}</span> @endisset
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($preview['shortages'] !== [])
                    <label class="mt-3 flex items-start gap-2 text-sm text-ink">
                        <input type="checkbox" wire:model="acknowledgeShortages" class="mt-0.5 size-4 accent-brand-600" data-ack-shortages>
                        <span>{{ __('orders.confirm.acknowledge') }}</span>
                    </label>
                @endif
                <p class="mt-3 text-sm text-ink" data-credit>
                    @if ($preview['credit']['limit'] === null)
                        {{ __('orders.confirm.no_limit') }}
                    @else
                        {{ __('orders.confirm.credit', ['exposure' => \App\Support\Money::of($preview['credit']['exposure'], $preview['credit']['currency'])->format(), 'limit' => \App\Support\Money::of($preview['credit']['limit'], $preview['credit']['currency'])->format()]) }}
                        @if ($preview['exceeds']) <span class="font-semibold text-brand-700">{{ __('orders.confirm.exceeds') }}</span> @endif
                    @endif
                </p>
            @endif

            @if ($panel === 'details')
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="d-contact" class="form-label">{{ __('orders.fields.contact') }}</label>
                        <select wire:model="details.contact_id" id="d-contact" class="form-input">
                            <option value="">—</option>
                            @foreach ($order->customer?->contacts ?? [] as $contact)
                                <option value="{{ $contact->id }}">{{ $contact->fullName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="d-billing" class="form-label">{{ __('orders.fields.billing_contact') }}</label>
                        <select wire:model="details.billing_contact_id" id="d-billing" class="form-input">
                            <option value="">—</option>
                            @foreach ($order->customer?->contacts ?? [] as $contact)
                                <option value="{{ $contact->id }}">{{ $contact->fullName() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="d-requested" class="form-label">{{ __('orders.fields.requested_delivery_on') }}</label>
                        <input wire:model="details.requested_delivery_on" id="d-requested" type="date" class="form-input">
                        @error('details.requested_delivery_on') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="d-promised" class="form-label">{{ __('orders.fields.promised_delivery_on') }}</label>
                        <input wire:model="details.promised_delivery_on" id="d-promised" type="date" class="form-input">
                        @error('details.promised_delivery_on') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="d-address" class="form-label">{{ __('orders.fields.delivery_address') }}</label>
                        <textarea wire:model="details.delivery_address" id="d-address" rows="2" class="form-input"></textarea>
                    </div>
                </div>
            @endif

            @if ($panel === 'sale_type')
                <fieldset class="mt-4">
                    <legend class="form-label">{{ __('orders.fields.sale_type') }}</legend>
                    <div class="flex flex-wrap gap-4">
                        @foreach (\App\Enums\SaleType::cases() as $type)
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="saleType" type="radio" value="{{ $type->value }}" class="accent-brand-600"> {{ $type->label() }}</label>
                        @endforeach
                    </div>
                    @error('saleType') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-2 text-xs text-muted">{{ __('orders.sale_type_hint') }}</p>
                </fieldset>
            @endif

            @if ($panel === 'framework')
                <div class="mt-4">
                    <label for="p-framework" class="form-label">{{ __('orders.fields.framework') }}</label>
                    <select wire:model="frameworkId" id="p-framework" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->frameworkOptions as $option)
                            <option value="{{ $option->id }}">{{ $option->number }} · {{ $option->ends_on?->translatedFormat($dateFormat) }}</option>
                        @endforeach
                    </select>
                    @error('frameworkId') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            @if (in_array($panel, ['cancel', 'override', 'waive'], true))
                @if ($panel === 'override')
                    <p class="mt-2 text-sm text-ink">{{ \App\Enums\HoldReason::tryFrom($holdReason)?->label() }}</p>
                    @error('holdReason') <p class="form-error">{{ $message }}</p> @enderror
                @endif
                <div class="mt-4">
                    <label for="p-reason" class="form-label">{{ __('orders.fields.'.['cancel' => 'cancel_reason', 'override' => 'override_reason', 'waive' => 'waiver_reason'][$panel]) }}</label>
                    <textarea wire:model="reason" id="p-reason" rows="2" class="form-input"></textarea>
                    @error('reason') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="mt-4 flex justify-end gap-3">
                <button type="button" wire:click="closePanel" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                @php
                    $submitAction = ['confirm' => 'confirm', 'cancel' => 'cancel', 'override' => 'overrideHold', 'waive' => 'waive', 'details' => 'saveDetails', 'framework' => 'linkFramework', 'sale_type' => 'changeSaleType'][$panel];
                @endphp
                <button type="button" wire:click="{{ $submitAction }}" class="btn btn-primary" wire:loading.attr="disabled" data-confirm-panel>{{ __('orders.panels.confirm_'.$panel) }}</button>
            </div>
        </section>
    @endif

    {{-- Standard document tabs (user decision 2026-10-20): Header | Items | Other. --}}
    <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
        @if ($tab === 'header')
            <section class="card p-5" aria-label="{{ __('common.document_tabs.header') }}">
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3" data-order-header>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.offer') }}</dt>
                        <dd class="mt-1 text-ink">
                            <x-badge tone="outline" data-order-source="{{ $order->source->value }}">{{ $order->source->label() }}</x-badge>
                            @if ($order->offer && $user->can('view', $order->offer))
                                <a href="{{ route('offers.show', $order->offer) }}" wire:navigate class="link font-mono">{{ $order->offer->displayNumber() }}</a>
                            @elseif ($order->offer)
                                <span class="font-mono">{{ $order->offer->displayNumber() }}</span>
                            @endif
                            @if ($order->note) <span class="block text-xs text-muted">{{ $order->note }}</span> @endif
                        </dd>
                    </div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.contact') }}</dt><dd class="mt-1 text-ink">{{ $order->contact?->fullName() ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.billing_contact') }}</dt><dd class="mt-1 text-ink">{{ $order->billingContact?->fullName() ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.requested_delivery_on') }}</dt><dd class="mt-1 text-ink">{{ $order->requested_delivery_on?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.payment_terms') }}</dt><dd class="mt-1 text-ink">{{ $order->payment_terms_text ?: ($order->payment_terms_days !== null ? trans_choice('orders.show.days', $order->payment_terms_days, ['count' => $order->payment_terms_days]) : '—') }}</dd></div>
                    @if ($order->confirmed_at)
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.show.confirmed') }}</dt><dd class="mt-1 text-ink">{{ $order->confirmed_at->translatedFormat($dateTimeFormat) }}</dd></div>
                    @endif
                    @if ($order->cancelled_at)
                        <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.show.cancelled') }}</dt><dd class="mt-1 text-ink">{{ $order->cancelled_at->translatedFormat($dateTimeFormat) }} · {{ $order->cancel_reason }}</dd></div>
                    @endif
                    @if ($order->delivery_address)
                        <div class="sm:col-span-2"><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('orders.fields.delivery_address') }}</dt><dd class="mt-1 whitespace-pre-line text-ink">{{ $order->delivery_address }}</dd></div>
                    @endif
                </dl>
            </section>
        @elseif ($tab === 'items')
            <section class="card p-5" aria-label="{{ __('common.document_tabs.items') }}">
                <label class="mb-3 flex items-center gap-2 text-sm text-muted">
                    <input type="checkbox" wire:model.live="showCodes" class="size-4 accent-brand-600"> {{ __('orders.show.show_codes') }}
                </label>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-line text-sm" data-order-lines>
                        <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                            <tr>
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">{{ __('orders.lines.name') }}</th>
                                @if ($showCodes)
                                    <th class="py-2 pr-3">{{ __('orders.lines.accountant_code') }}</th>
                                    <th class="py-2 pr-3">{{ __('orders.lines.class_code') }}</th>
                                    <th class="py-2 pr-3">{{ __('orders.lines.package_code') }}</th>
                                @endif
                                <th class="py-2 pr-3 text-right">{{ __('orders.lines.quantity') }}</th>
                                <th class="py-2 pr-3 text-right">{{ __('orders.lines.delivered') }}</th>
                                <th class="py-2 pr-3 text-right">{{ __('orders.lines.price') }}</th>
                                <th class="py-2 text-right">{{ __('orders.lines.total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($order->lines as $line)
                                <tr wire:key="line-{{ $line->id }}">
                                    <td class="py-2 pr-3 align-top font-bold whitespace-nowrap">{{ $line->option_label }}</td>
                                    <td class="py-2 pr-3 align-top">
                                        <div class="flex gap-3">
                                        <x-item-thumb :url="$line->imageUrl()" :alt="$line->name" :size="40" />
                                        <div class="min-w-0">
                                        <span class="text-ink">{{ $line->name }}</span>
                                        <span class="block font-mono text-xs text-muted">{{ $line->sku }}@if ($line->group_title) · {{ $line->group_title }}@endif</span>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @if ($line->backorder) <x-badge tone="danger-outline" icon="warning">{{ __('orders.lines.backorder') }}</x-badge> @endif
                                            @if ($line->is_serialized) <x-badge tone="outline">{{ __('orders.lines.serialized') }}</x-badge> @endif
                                            @foreach ($line->reservations->whereIn('status', [\App\Enums\ReservationStatus::Active, \App\Enums\ReservationStatus::MirroredIn1c]) as $reservation)
                                                <x-badge tone="silver" wire:key="res-{{ $reservation->id }}">{{ __('orders.lines.reserved', ['qty' => \App\Support\Quantity::trimmed((string) $reservation->quantity)]) }}</x-badge>
                                            @endforeach
                                        </div>
                                        </div>
                                        </div>
                                    </td>
                                    @if ($showCodes)
                                        <td class="py-2 pr-3 align-top font-mono text-xs">{{ $line->accountant_code ?? '—' }}</td>
                                        <td class="py-2 pr-3 align-top font-mono text-xs">@if ($line->class_code){{ $line->class_code }}@else<span class="text-brand-700">{{ __('orders.lines.missing') }}</span>@endif</td>
                                        <td class="py-2 pr-3 align-top font-mono text-xs">@if ($line->package_code){{ $line->package_code }}@else<span class="text-brand-700">{{ __('orders.lines.missing') }}</span>@endif</td>
                                    @endif
                                    <td class="py-2 pr-3 text-right align-top whitespace-nowrap">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit_name }}</td>
                                    <td class="py-2 pr-3 text-right align-top whitespace-nowrap">{{ \App\Support\Quantity::trimmed((string) $line->delivered_qty) }}</td>
                                    <td class="py-2 pr-3 text-right align-top whitespace-nowrap">{{ $order->money($line->unit_price_minor)->format() }}@if ($line->discount_bp > 0) <span class="block text-xs text-muted">−{{ rtrim(rtrim(bcdiv((string) $line->discount_bp, '100', 2), '0'), '.') }}%</span>@endif</td>
                                    <td class="py-2 text-right align-top whitespace-nowrap">{{ $order->money($order->prices_include_vat ? $line->line_gross_minor : $line->line_net_minor)->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <div class="space-y-4" data-order-other>
                @can('viewPayments', $order)
                    <section class="card p-5" aria-labelledby="order-payments-heading">
                        <h3 id="order-payments-heading" class="mb-3 font-semibold text-ink">{{ __('orders.show.tab_payments') }}</h3>
                            <livewire:orders.payments :order-id="$order->id" :key="'payments-'.$order->id" />
                    </section>
                @endcan
                <section class="card p-5" aria-labelledby="order-deliveries-heading">
                    <h3 id="order-deliveries-heading" class="mb-3 font-semibold text-ink">{{ __('orders.show.tab_deliveries') }}</h3>
                    <div class="space-y-3" data-deliveries-tab>
                        @can('create', [\App\Models\Delivery::class, $order])
                            @if (app(\App\Services\Orders\OrderFlow::class)->openPackingList($order) === null)
                                <button type="button" wire:click="createDelivery" class="btn btn-primary btn-sm" data-action="create-delivery">{{ __('orders.actions.create_packing_list') }}</button>
                            @endif
                        @endcan
                        <ul class="divide-y divide-line text-sm">
                            @forelse ($order->deliveries()->with('warehouse')->get() as $delivery)
                                <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="del-{{ $delivery->id }}">
                                    <span><a href="{{ route('delivery-notes.show', $delivery) }}" wire:navigate class="link font-mono">{{ $delivery->number }}</a> · {{ $delivery->warehouse?->name }}@if ($delivery->delivered_on) · {{ $delivery->delivered_on->translatedFormat($dateFormat) }}@endif</span>
                                    @php
                                        [$dTone, $dIcon] = $delivery->status->badge();
                                    @endphp
                                    <span class="flex gap-2">
                                        @if ($delivery->status === \App\Enums\StockDocumentStatus::Draft)
                                            <x-badge :tone="$delivery->isPacked() ? 'dark' : 'outline'" :icon="$delivery->isPacked() ? 'check' : 'clock'" data-packing="{{ $delivery->isPacked() ? 'packed' : 'packing' }}">{{ $delivery->isPacked() ? __('stock.packaging.packed') : __('stock.packaging.packing') }}</x-badge>
                                        @endif
                                        <x-badge :tone="$dTone" :icon="$dIcon">{{ $delivery->status->label() }}</x-badge>
                                    </span>
                                </li>
                            @empty
                                <li class="py-2 text-muted">{{ __('orders.show.no_deliveries') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </section>
                <section class="card p-5" aria-labelledby="order-agreement-heading">
                    <h3 id="order-agreement-heading" class="mb-3 font-semibold text-ink">{{ __('orders.show.tab_agreement') }}</h3>
                    <div class="space-y-4" data-agreement-tab>
                        @if ($agreement)
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <a href="{{ route('agreements.show', $agreement) }}" wire:navigate class="link font-mono font-semibold">{{ $agreement->number }}</a>
                                    <x-agreements.status-badge :status="$agreement->status" />
                                    @if ($agreement->sent_at) <p class="text-xs text-muted">{{ __('agreements.show.sent_at', ['date' => $agreement->sent_at->translatedFormat($dateTimeFormat)]) }}</p> @endif
                                    @if ($agreement->signed_on) <p class="text-xs text-muted">{{ __('agreements.show.signed_on', ['date' => $agreement->signed_on->translatedFormat($dateFormat)]) }}</p> @endif
                                </div>
                            </div>
                        @elseif ($this->framework)
                            <p class="text-sm text-ink">{{ __('orders.show.under_framework', ['number' => $this->framework->number]) }}</p>
                            @can('linkFramework', $order)
                                <button type="button" wire:click="unlinkFramework" class="btn btn-secondary btn-sm">{{ __('orders.actions.unlink_framework') }}</button>
                            @endcan
                        @elseif (! $order->agreement_required)
                            <p class="text-sm text-ink">{{ __('orders.show.agreement_waived', ['reason' => $order->agreement_waiver_reason]) }}</p>
                        @else
                            <x-empty-state :title="__('orders.show.no_agreement')" :description="__('orders.show.no_agreement_hint')" icon="agreements">
                                @can('create', [\App\Models\Agreement::class, $order])
                                    <button type="button" wire:click="generateAgreement" class="btn btn-primary" data-action="generate-agreement">{{ __('orders.actions.generate_agreement') }}</button>
                                @endcan
                                @can('linkFramework', $order)
                                    @if ($this->frameworkOptions->isNotEmpty())
                                        <button type="button" wire:click="openPanel('framework')" class="btn btn-secondary">{{ __('orders.actions.link_framework') }}</button>
                                    @endif
                                @endcan
                                @can('waiveAgreement', $order)
                                    <button type="button" wire:click="openPanel('waive')" class="btn btn-secondary" data-action="waive">{{ __('orders.actions.waive_agreement') }}</button>
                                @endcan
                            </x-empty-state>
                        @endif
                    </div>
                </section>
                <section class="card p-5" aria-labelledby="order-history-heading">
                    <h3 id="order-history-heading" class="mb-3 font-semibold text-ink">{{ __('common.document_tabs.history') }}</h3>
                    <ol class="divide-y divide-line" data-timeline>
                        @forelse ($this->timeline as $item)
                            @php
                                $model = $item['model'];
                            @endphp
                            <li class="py-3 text-sm" wire:key="tl-{{ $item['kind'] }}-{{ $model->id }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <p class="text-ink">
                                        @if ($item['kind'] === 'transition')
                                            <span class="font-semibold">{{ $model->from_status ? __('orders.show.transition', ['from' => \App\Enums\OrderStatus::from($model->from_status)->label(), 'to' => \App\Enums\OrderStatus::from($model->to_status)->label()]) : __('orders.show.created_as', ['status' => \App\Enums\OrderStatus::from($model->to_status)->label()]) }}</span>
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
                </section>
            </div>
        @endif
    </x-tabs>
</div>
