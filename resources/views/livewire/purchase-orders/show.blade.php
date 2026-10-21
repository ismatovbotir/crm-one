<div class="space-y-6">
    @php
        $po = $this->po;
        $user = auth()->user();
        $editable = $user->can('edit', $po);
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $trim = fn ($value) => $value === null ? '—' : \App\Support\Quantity::trimmed((string) $value);
    @endphp

    <a href="{{ route('purchase-orders.index') }}" wire:navigate class="link text-sm">&larr; {{ __('purchase_orders.back') }}</a>

    <x-flash />

    <header class="card p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="font-mono text-sm text-muted">{{ $po->number }} · {{ __('purchase_orders.sources.'.$po->source) }}</p>
                <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ $po->supplier?->name }}</h2>
                <div class="mt-3 flex flex-wrap gap-2">@php([$tone, $icon] = $po->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $po->status->label() }}</x-badge></div>
            </div>
            <div class="flex flex-wrap gap-2" data-po-actions>
                @can('confirm', $po)
                    <button type="button" wire:click="confirm" class="btn btn-primary" data-action="confirm">{{ __('purchase_orders.actions.confirm') }}</button>
                @endcan
                @can('createTransportTender', $po)
                    <button type="button" wire:click="createTransportTender" @if ($exwWarning) wire:confirm="{{ __('purchase_orders.exw_warning') }}" @endif class="btn btn-primary" data-action="create-transport-tender">{{ __('purchase_orders.actions.create_transport_tender') }}</button>
                @endcan
                @can('print', $po)
                    <a href="{{ route('purchase-orders.print', $po) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('purchase_orders.actions.print') }}</a>
                @endcan
                @can('cancel', $po)
                    <button type="button" wire:click="$toggle('cancelling')" class="btn btn-danger-outline">{{ __('purchase_orders.actions.cancel') }}</button>
                @endcan
            </div>
        </div>

        @if ($cancelling)
            <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                <div class="min-w-64 flex-1"><label for="po-cancel" class="form-label">{{ __('purchase_orders.fields.cancel_reason') }}</label><input wire:model="cancelReason" id="po-cancel" type="text" class="form-input"></div>
                <button type="button" wire:click="cancel" class="btn btn-danger-outline">{{ __('purchase_orders.actions.cancel') }}</button>
            </div>
        @endif

        @if ($editable)
            <form wire:submit="saveHeader" class="mt-6 grid gap-4 border-t border-line pt-6 sm:grid-cols-2 lg:grid-cols-4" novalidate data-po-header>
                <div><label for="h-ref" class="form-label">{{ __('purchase_orders.fields.supplier_order_ref') }}</label><input wire:model="header.supplier_order_ref" id="h-ref" type="text" class="form-input"></div>
                <div>
                    <label for="h-cur" class="form-label">{{ __('purchase_orders.fields.currency') }}</label>
                    <select wire:model="header.currency" id="h-cur" class="form-input">@foreach (config('tenders.currencies') as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="h-inco" class="form-label">{{ __('purchase_orders.fields.incoterm') }}</label>
                    <select wire:model="header.incoterm" id="h-inco" class="form-input"><option value="">—</option>@foreach ($incoterms as $value => $label)<option value="{{ $value }}">{{ $value }} — {{ $label }}</option>@endforeach</select>
                </div>
                <div><label for="h-place" class="form-label">{{ __('purchase_orders.fields.incoterm_place') }}</label><input wire:model="header.incoterm_place" id="h-place" type="text" class="form-input"></div>
                <div><label for="h-pc" class="form-label">{{ __('purchase_orders.fields.pickup_country') }}</label><input wire:model="header.pickup_country" id="h-pc" type="text" maxlength="2" class="form-input uppercase">@error('header.pickup_country') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="h-pcity" class="form-label">{{ __('purchase_orders.fields.pickup_city') }}</label><input wire:model="header.pickup_city" id="h-pcity" type="text" class="form-input"></div>
                <div class="sm:col-span-2"><label for="h-paddr" class="form-label">{{ __('purchase_orders.fields.pickup_address') }}</label><input wire:model="header.pickup_address" id="h-paddr" type="text" class="form-input"></div>
                <div>
                    <label for="h-wh" class="form-label">{{ __('purchase_orders.fields.warehouse') }}</label>
                    <select wire:model="header.destination_warehouse_id" id="h-wh" class="form-input"><option value="">—</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach</select>
                </div>
                <div><label for="h-ready" class="form-label">{{ __('purchase_orders.fields.readiness_date') }}</label><input wire:model="header.readiness_date" id="h-ready" type="date" class="form-input"></div>
                <div><label for="h-req" class="form-label">{{ __('purchase_orders.fields.required_delivery_date') }}</label><input wire:model="header.required_delivery_date" id="h-req" type="date" class="form-input"></div>
                <div><label for="h-terms" class="form-label">{{ __('purchase_orders.fields.payment_terms') }}</label><input wire:model="header.payment_terms" id="h-terms" type="text" class="form-input"></div>
                <fieldset class="sm:col-span-2 lg:col-span-4">
                    <legend class="form-label">{{ __('purchase_orders.fields.documents_available') }}</legend>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        @foreach (config('tenders.vocab.documents') as $document)
                            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="header.documents_available" value="{{ $document }}" type="checkbox" class="rounded border-line"> {{ __('tenders.vocab.documents.'.$document) }}</label>
                        @endforeach
                    </div>
                </fieldset>
                <div class="sm:col-span-2 lg:col-span-4"><label for="h-special" class="form-label">{{ __('purchase_orders.fields.special_handling') }}</label><input wire:model="header.special_handling" id="h-special" type="text" class="form-input"></div>
                <div class="flex justify-end sm:col-span-2 lg:col-span-4"><button type="submit" class="btn btn-primary" data-action="save-po-header">{{ __('common.save') }}</button></div>
            </form>
        @endif

        <dl class="mt-6 grid gap-x-6 gap-y-4 border-t border-line pt-6 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('purchase_orders.fields.subtotal') }}</dt><dd class="mt-1 font-semibold text-ink">{{ $po->money($po->subtotal_minor)->format() }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('purchase_orders.fields.incoterm') }}</dt><dd class="mt-1 text-ink">{{ trim($po->incoterm.' '.$po->incoterm_place) ?: '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('purchase_orders.fields.pickup') }}</dt><dd class="mt-1 text-ink">{{ collect([$po->pickup_country, $po->pickup_city, $po->pickup_address])->filter()->implode(', ') ?: '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('purchase_orders.fields.readiness_date') }}</dt><dd class="mt-1 text-ink">{{ $po->readiness_date?->translatedFormat($dateFormat) ?? '—' }}</dd></div>
            <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('purchase_orders.fields.tender') }}</dt>
                <dd class="mt-1">
                    @if ($po->award?->tender)<a href="{{ route('tenders.show', $po->award->tender) }}" wire:navigate class="link font-mono">{{ $po->award->tender->number }}</a>@endif
                    @foreach ($this->tenders as $tender)<a href="{{ route('tenders.show', $tender) }}" wire:navigate class="link block font-mono" data-transport-tender>{{ $tender->number }}</a>@endforeach
                    @if (! $po->award && $this->tenders->isEmpty()) — @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs font-medium tracking-wide text-muted uppercase"><label for="po-onec">{{ __('purchase_orders.fields.onec_number') }}</label></dt>
                <dd class="mt-1 flex gap-2">
                    @can('update', $po)
                        <input wire:model="onecNumber" id="po-onec" type="text" class="form-input" maxlength="30">
                        <button type="button" wire:click="saveOnecNumber" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                    @else
                        <span class="text-ink">{{ $po->onec_number ?? '—' }}</span>
                    @endcan
                </dd>
            </div>
        </dl>
    </header>

    <section class="card p-5">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm" data-po-lines>
                <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                    <tr><th scope="col" class="py-2">#</th><th scope="col" class="py-2">{{ __('purchase_orders.fields.item') }}</th><th scope="col" class="py-2 text-right">{{ __('purchase_orders.fields.quantity') }}</th><th scope="col" class="py-2 text-right">{{ __('purchase_orders.fields.unit_price') }}</th><th scope="col" class="py-2 text-right">{{ __('purchase_orders.fields.total') }}</th><th scope="col" class="py-2">{{ __('purchase_orders.fields.cargo') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($po->lines as $row)
                        <tr wire:key="pol-{{ $row->id }}" class="align-top">
                            <td class="py-2 text-muted">{{ $row->position }}</td>
                            <td class="py-2 text-ink">{{ $row->name }}@if ($row->sku) <span class="font-mono text-xs text-muted">{{ $row->sku }}</span>@endif @if ($row->offered_brand || $row->offered_model)<span class="block text-xs text-muted">{{ trim($row->offered_brand.' '.$row->offered_model) }}</span>@endif</td>
                            <td class="py-2 text-right whitespace-nowrap text-ink">{{ \App\Support\Quantity::trimmed((string) $row->quantity) }} {{ $row->unit_code }}</td>
                            <td class="py-2 text-right whitespace-nowrap text-ink">{{ $po->money($row->unit_price_minor)->format() }}</td>
                            <td class="py-2 text-right whitespace-nowrap text-ink">{{ $po->money($row->line_total_minor)->format() }}</td>
                            <td class="py-2 text-xs text-muted">{{ __('purchase_orders.cargo_line', ['hs' => $row->hs_code ?? '—', 'gross' => $trim($row->gross_weight_kg), 'volume' => $trim($row->volume_m3), 'packages' => $row->packages_count ?? '—']) }}
                                <x-badge :tone="$row->weight_source === 'packing_list' ? 'silver' : 'outline'">{{ __('purchase_orders.weight_sources.'.$row->weight_source) }}</x-badge></td>
                            <td class="py-2 text-right whitespace-nowrap">
                                @if ($editable)
                                    <button type="button" wire:click="editLine({{ $row->id }})" class="btn btn-secondary btn-sm">{{ __('common.edit') }}</button>
                                    <button type="button" wire:click="removeLine({{ $row->id }})" class="btn btn-secondary btn-sm">{{ __('tenders.remove') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-3 text-sm text-muted">{{ __('purchase_orders.no_lines') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($editable)
            <form wire:submit="saveLine" class="mt-4 grid gap-3 border-t border-line pt-4 sm:grid-cols-4" novalidate data-po-line-form>
                <div class="sm:col-span-4">
                    <label for="pl-search" class="form-label">{{ __('tenders.items.catalog_search') }}</label>
                    <input wire:model.live.debounce.300ms="itemSearch" id="pl-search" type="search" class="form-input" placeholder="{{ __('tenders.items.catalog_placeholder') }}">
                    @if ($this->itemOptions->isNotEmpty())
                        <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                            @foreach ($this->itemOptions as $option)
                                <li wire:key="pio-{{ $option->id }}"><button type="button" wire:click="pickItem({{ $option->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-brand-50">{{ $option->name }} <span class="font-mono text-xs text-muted">{{ $option->sku }}</span></button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div class="sm:col-span-2"><label for="pl-name" class="form-label">{{ __('purchase_orders.fields.item') }}</label><input wire:model="line.name" id="pl-name" type="text" class="form-input" @disabled($catalogItemId !== null)>@error('line.name') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="pl-qty" class="form-label">{{ __('purchase_orders.fields.quantity') }}</label><input wire:model="line.quantity" id="pl-qty" type="text" inputmode="decimal" class="form-input">@error('line.quantity') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="pl-price" class="form-label">{{ __('purchase_orders.fields.unit_price') }} ({{ $po->currency }})</label><input wire:model="line.unit_price" id="pl-price" type="text" inputmode="decimal" class="form-input">@error('line.unit_price') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="pl-hs" class="form-label">{{ __('tenders.items.hs_code') }}</label><input wire:model="line.hs_code" id="pl-hs" type="text" class="form-input">@error('line.hs_code') <p class="form-error">{{ $message }}</p> @enderror</div>
                <div><label for="pl-origin" class="form-label">{{ __('purchase_orders.fields.country_of_origin') }}</label><input wire:model="line.country_of_origin" id="pl-origin" type="text" maxlength="2" class="form-input uppercase"></div>
                <div><label for="pl-gross" class="form-label">{{ __('purchase_orders.fields.gross_weight_kg') }}</label><input wire:model="line.gross_weight_kg" id="pl-gross" type="text" inputmode="decimal" class="form-input"></div>
                <div><label for="pl-net" class="form-label">{{ __('purchase_orders.fields.net_weight_kg') }}</label><input wire:model="line.net_weight_kg" id="pl-net" type="text" inputmode="decimal" class="form-input"></div>
                <div><label for="pl-vol" class="form-label">{{ __('purchase_orders.fields.volume_m3') }}</label><input wire:model="line.volume_m3" id="pl-vol" type="text" inputmode="decimal" class="form-input"></div>
                <div><label for="pl-pk" class="form-label">{{ __('purchase_orders.fields.packages_count') }}</label><input wire:model="line.packages_count" id="pl-pk" type="number" min="0" class="form-input"></div>
                <div><label for="pl-pt" class="form-label">{{ __('purchase_orders.fields.package_type') }}</label><input wire:model="line.package_type" id="pl-pt" type="text" class="form-input"></div>
                <div>
                    <label for="pl-ws" class="form-label">{{ __('purchase_orders.fields.weight_source') }}</label>
                    <select wire:model="line.weight_source" id="pl-ws" class="form-input"><option value="packing_list">{{ __('purchase_orders.weight_sources.packing_list') }}</option><option value="manual">{{ __('purchase_orders.weight_sources.manual') }}</option></select>
                </div>
                <p class="text-xs text-muted sm:col-span-4">{{ __('purchase_orders.weight_hint') }}</p>
                <div class="flex justify-end gap-2 sm:col-span-4">
                    @if ($editingLineId || $catalogItemId)<button type="button" wire:click="resetLine" class="btn btn-secondary">{{ __('common.cancel') }}</button>@endif
                    <button type="submit" class="btn btn-primary">{{ $editingLineId ? __('common.save') : __('purchase_orders.add_line') }}</button>
                </div>
            </form>
        @endif
    </section>

    <section class="card p-5">
        <h3 class="font-semibold text-ink">{{ __('purchase_orders.history_title') }}</h3>
        <ol class="mt-2 divide-y divide-line">
            @foreach ($po->transitions as $transition)
                <li class="py-2 text-sm" wire:key="pot-{{ $transition->id }}"><span class="font-semibold text-ink">{{ \App\Enums\PurchaseOrderStatus::from($transition->to_status)->label() }}</span> <span class="text-muted">· {{ $transition->user?->name ?? __('activity.system') }} · {{ $transition->created_at->setTimezone((string) config('tenders.timezone'))->translatedFormat($dateTimeFormat) }}</span>@if ($transition->reasonLabel())<span class="block text-xs text-muted">{{ $transition->reasonLabel() }}</span>@endif</li>
            @endforeach
        </ol>
    </section>
</div>
