<div class="space-y-6">
    @php
        $receipt = $this->receipt;
        $editable = auth()->user()->can('update', $receipt);
        $fromOneC = $receipt->isFromOneC();
        $needsReason = \App\Actions\Stock\TransitionGoodsReceipt::NEEDS_REASON;
        $primary = [\App\Enums\GoodsReceiptStatus::Posted, \App\Enums\GoodsReceiptStatus::Checked];
        $moveKey = fn (\App\Enums\GoodsReceiptStatus $to): string => $to === \App\Enums\GoodsReceiptStatus::InProgress ? match ($receipt->status) {
            \App\Enums\GoodsReceiptStatus::Checked => 'reopen',
            \App\Enums\GoodsReceiptStatus::OnHold => 'release',
            default => 'start',
        } : $to->value;
        $reasonMoves = array_values(array_filter($this->moves, fn ($to) => in_array($to, $needsReason, true)));
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
    @endphp

    <a href="{{ route('goods-receipts.index') }}" wire:navigate class="link text-sm">&larr; {{ __('stock.receipts.back') }}</a>

    <x-flash />

    <header class="card flex flex-col gap-4 p-6 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="font-mono text-sm text-muted">{{ $receipt->source_type->label() }} · {{ $receipt->received_on->translatedFormat($dateFormat) }}</p>
            <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ $receipt->number }}</h2>
            <p class="mt-1 text-sm text-ink">{{ $receipt->warehouse?->name }}@if ($receipt->supplierPartner) · <a href="{{ route('partners.show', $receipt->supplierPartner) }}" wire:navigate class="link">{{ $receipt->supplierPartner->name }}</a> <span class="font-mono text-xs text-muted">{{ $receipt->supplierPartner->tax_id }}</span>@elseif ($receipt->counterparty_name) · {{ $receipt->counterparty_name }}@endif @if (! $fromOneC && $receipt->supplier_doc_ref) · {{ $receipt->supplier_doc_ref }}@endif @if ($receipt->returnOfDelivery) · {{ __('stock.fields.return_of_delivery') }} {{ $receipt->returnOfDelivery->number }} ({{ $receipt->customer?->name }})@endif</p>
            @if ($fromOneC)
                <p class="mt-1 text-xs text-muted" data-onec-document>{{ __('stock.receipts.onec_document', ['number' => $receipt->external_number, 'date' => $receipt->external_date?->translatedFormat($dateFormat), 'version' => $receipt->sync_version]) }}</p>
            @endif
            <x-stock.receipt-stepper :receipt="$receipt" class="mt-3" />
        </div>
        <div class="flex flex-wrap gap-2">
            @can('print', $receipt)
                <a href="{{ route('goods-receipts.print', $receipt) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('stock.actions.print') }}</a>
            @endcan
            @foreach ($this->moves as $to)
                @continue(in_array($to, $needsReason, true))
                @php
                    $isPrimary = in_array($to, $primary, true) || $moveKey($to) === 'start';
                @endphp
                <button type="button" wire:click="move('{{ $to->value }}')" wire:loading.attr="disabled" class="btn {{ $isPrimary ? 'btn-primary' : 'btn-secondary' }}" data-action="{{ $moveKey($to) }}">{{ __('stock.receipts.moves.'.$moveKey($to)) }}</button>
            @endforeach
        </div>
    </header>

    @if ($receipt->needs_review)
        <section class="card border-l-4 border-l-ink p-5" role="status" data-review-panel>
            <p class="font-semibold text-ink"><x-icon name="warning" class="inline size-4" /> {{ __('stock.receipts.review_reasons.'.$receipt->review_reason) }}</p>
            <p class="mt-1 text-sm text-muted">{{ __('stock.receipts.review_help.'.$receipt->review_reason) }}</p>
            @can('resolveReview', $receipt)
                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="grow">
                        <label for="review-note" class="form-label">{{ __('stock.receipts.review_note') }}</label>
                        <input wire:model="reason" id="review-note" type="text" class="form-input" maxlength="500">
                    </div>
                    <button type="button" wire:click="resolveReview" class="btn btn-secondary" data-action="resolve-review">{{ __('stock.receipts.resolve_review') }}</button>
                </div>
            @endcan
        </section>
    @endif

    @if ($reasonMoves !== [])
        <section class="card flex flex-col gap-2 p-5 sm:flex-row sm:items-end" data-reason-moves>
            <div class="grow">
                <label for="move-reason" class="form-label">{{ __('stock.receipts.move_reason') }}</label>
                <input wire:model="reason" id="move-reason" type="text" class="form-input" maxlength="500">
            </div>
            @foreach ($reasonMoves as $to)
                @if ($to === \App\Enums\GoodsReceiptStatus::Cancelled)
                    <button type="button" wire:click="move('{{ $to->value }}')" wire:confirm="{{ __('stock.receipts.cancel_confirm') }}" class="btn btn-danger-outline" data-action="{{ $to->value }}">{{ __('stock.receipts.moves.'.$to->value) }}</button>
                @else
                    <button type="button" wire:click="move('{{ $to->value }}')" class="btn btn-secondary" data-action="{{ $to->value }}">{{ __('stock.receipts.moves.'.$to->value) }}</button>
                @endif
            @endforeach
        </section>
    @endif

    {{-- Standard document tabs (user decision 2026-10-20): Header | Items | Other. --}}
    <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
        @if ($tab === 'header')
            <section class="card p-5" aria-label="{{ __('common.document_tabs.header') }}" data-receipt-header>
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.source') }}</dt><dd class="mt-1 text-ink">{{ $receipt->source_type->label() }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.warehouse') }}</dt><dd class="mt-1 text-ink">{{ $receipt->warehouse?->name }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.received') }}</dt><dd class="mt-1 text-ink">{{ $receipt->received_on->translatedFormat($dateFormat) }}</dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.counterparty') }}</dt><dd class="mt-1 text-ink">
                        @if ($receipt->supplierPartner)
                            <a href="{{ route('partners.show', $receipt->supplierPartner) }}" wire:navigate class="link">{{ $receipt->supplierPartner->name }}</a> <span class="font-mono text-xs text-muted">{{ $receipt->supplierPartner->tax_id }}</span>
                        @else
                            {{ $receipt->counterparty_name ?: '—' }}
                        @endif
                    </dd></div>
                    <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.supplier_doc_ref') }}</dt><dd class="mt-1 text-ink">{{ $receipt->supplier_doc_ref ?: '—' }}</dd></div>
                    @if ($fromOneC)
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.receipts.origins.onec') }}</dt><dd class="mt-1 text-ink">{{ __('stock.receipts.onec_document', ['number' => $receipt->external_number, 'date' => $receipt->external_date?->translatedFormat($dateFormat), 'version' => $receipt->sync_version]) }}</dd></div>
                    @endif
                    @if ($receipt->returnOfDelivery)
                        <div><dt class="text-xs font-medium tracking-wide text-muted uppercase">{{ __('stock.fields.return_of_delivery') }}</dt><dd class="mt-1 text-ink"><a href="{{ route('delivery-notes.show', $receipt->returnOfDelivery) }}" wire:navigate class="link font-mono">{{ $receipt->returnOfDelivery->number }}</a> · {{ $receipt->customer?->name }}</dd></div>
                    @endif
                </dl>
            </section>
        @elseif ($tab === 'items')
            <div class="space-y-6">
                @if ($editable && ! $fromOneC && $receipt->source_type !== \App\Enums\ReceiptSource::CustomerReturn)
                    <section class="card p-5">
                        <label for="item-search" class="form-label">{{ __('stock.receipts.add_item') }}</label>
                        <input wire:model.live.debounce.300ms="itemSearch" id="item-search" type="search" class="form-input" placeholder="{{ __('stock.receipts.item_search') }}">
                        <ul class="mt-2 divide-y divide-line text-sm">
                            @foreach ($this->itemOptions as $item)
                                <li class="flex items-center justify-between py-2" wire:key="opt-{{ $item->id }}">
                                    <span><span class="font-mono text-muted">{{ $item->sku }}</span> {{ $item->name }} @if ($item->is_serialized) <x-badge tone="outline">{{ __('orders.lines.serialized') }}</x-badge> @endif</span>
                                    <button type="button" wire:click="addItem({{ $item->id }})" class="btn btn-secondary btn-sm">{{ __('stock.receipts.add') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                @forelse ($receipt->lines as $line)
                    <section class="card space-y-4 p-5" wire:key="line-{{ $line->id }}" data-receipt-line="{{ $line->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="font-semibold text-ink">{{ $line->position }}. {{ $line->item?->name }} <span class="font-mono text-xs text-muted">{{ $line->item?->sku }}</span> @if ($line->is_serialized) <x-badge tone="outline">{{ __('orders.lines.serialized') }}</x-badge> @endif @if ($line->item?->needs_review) <x-badge tone="outline" icon="warning">{{ __('stock.receipts.item_needs_review') }}</x-badge> @endif</p>
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($line->expected_qty !== null && bccomp((string) $line->received_qty, (string) $line->expected_qty, 3) !== 0)
                                    @php
                                        $diff = bcsub((string) $line->received_qty, (string) $line->expected_qty, 3);
                                    @endphp
                                    @php
                                        $kind = bccomp($diff, '0', 3) < 0 ? 'shortage' : 'overage';
                                    @endphp
                                    <x-badge tone="outline" icon="warning" data-line-discrepancy="{{ $kind }}">{{ __('stock.receipts.'.$kind, ['qty' => \App\Support\Quantity::trimmed(ltrim($diff, '-'))]) }}</x-badge>
                                @endif
                                @if (bccomp((string) $line->damaged_qty, '0', 3) > 0)
                                    <x-badge tone="outline" icon="warning" data-line-damaged>{{ __('stock.receipts.damaged_badge', ['qty' => \App\Support\Quantity::trimmed((string) $line->damaged_qty)]) }}</x-badge>
                                @endif
                                @if ($editable && ! $fromOneC)
                                    <button type="button" wire:click="removeLine({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('stock.receipts.remove_line') }}</button>
                                @endif
                            </div>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                            @foreach (['expected_qty', 'received_qty', 'damaged_qty'] as $field)
                                <div>
                                    <label for="{{ $field }}-{{ $line->id }}" class="text-xs text-muted">{{ __('stock.fields.'.$field) }}</label>
                                    @php
                                        $counted = $line->is_serialized && $field !== 'expected_qty';
                                    @endphp
                                    <input wire:model="edits.{{ $line->id }}.{{ $field }}" id="{{ $field }}-{{ $line->id }}" type="text" inputmode="decimal" class="form-input" @disabled(! $editable || ($field === 'expected_qty' && $fromOneC) || $counted) @if ($counted) readonly aria-describedby="counted-{{ $line->id }}" data-counted="{{ $field }}" @endif>
                                </div>
                            @endforeach
                            <div>
                                <label for="reason-{{ $line->id }}" class="text-xs text-muted">{{ __('stock.fields.discrepancy_reason') }}</label>
                                <select wire:model="edits.{{ $line->id }}.discrepancy_reason" id="reason-{{ $line->id }}" class="form-input" @disabled(! $editable)>
                                    <option value="">—</option>
                                    @foreach ($reasons as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="note-{{ $line->id }}" class="text-xs text-muted">{{ __('stock.fields.line_note') }}</label>
                                <input wire:model="edits.{{ $line->id }}.note" id="note-{{ $line->id }}" type="text" class="form-input" maxlength="500" @disabled(! $editable)>
                            </div>
                            @if ($editable)
                                <div class="flex items-end"><button type="button" wire:click="saveLine({{ $line->id }})" class="btn btn-secondary btn-sm w-full" data-save-line>{{ __('common.save') }}</button></div>
                            @endif
                        </div>
                        @if ($line->is_serialized)
                            <p id="counted-{{ $line->id }}" class="text-xs text-muted">{{ __('stock.receipts.counted_from_serials') }}@if ($line->expected_qty !== null) · {{ __('stock.receipts.missing', ['qty' => \App\Support\Quantity::trimmed(bccomp((string) $line->expected_qty, (string) $line->received_qty, 3) > 0 ? bcsub((string) $line->expected_qty, (string) $line->received_qty, 3) : '0')]) }}@endif</p>
                            <livewire:stock.serial-entry :line-type="$serialLineType" :line-id="$line->id" :key="'se-gr-'.$line->id" />
                        @endif
                    </section>
                @empty
                    <x-empty-state :title="__('stock.receipts.no_lines')" icon="items" />
                @endforelse
            </div>
        @else
            <section class="card p-5" data-receipt-history>
                <h3 class="font-semibold text-ink">{{ __('stock.receipts.history') }}</h3>
                <ol class="mt-2 divide-y divide-line">
                    @foreach ($receipt->transitions as $transition)
                        <li class="py-2 text-sm" wire:key="grt-{{ $transition->id }}"><span class="font-semibold text-ink">{{ \App\Enums\GoodsReceiptStatus::tryFrom($transition->to_status)?->label() ?? $transition->to_status }}</span> <span class="text-muted">· {{ $transition->user?->name ?? __('activity.system') }} · {{ $transition->created_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</span>@if ($transition->reasonLabel())<span class="block text-xs text-muted">{{ $transition->reasonLabel() }}</span>@endif</li>
                    @endforeach
                </ol>
            </section>
        @endif
    </x-tabs>
</div>
