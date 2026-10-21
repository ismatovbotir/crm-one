<div class="space-y-6">
    @php
        $adjustment = $this->adjustment;
        $editable = auth()->user()->can('update', $adjustment);
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $steps = \App\Enums\StockAdjustmentStatus::steps();
        $off = in_array($adjustment->status, [\App\Enums\StockAdjustmentStatus::Rejected, \App\Enums\StockAdjustmentStatus::Cancelled], true);
        $reached = $off ? -1 : array_search($adjustment->status, $steps, true);
        $noteMoves = [\App\Enums\StockAdjustmentStatus::Posted, \App\Enums\StockAdjustmentStatus::Rejected, \App\Enums\StockAdjustmentStatus::Cancelled];
        $type = $adjustment->type;
    @endphp

    <a href="{{ route('stock-adjustments.index') }}" wire:navigate class="link text-sm">&larr; {{ __('stock_adjustments.back') }}</a>

    <x-flash />

    <header class="card flex flex-col gap-4 p-6 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="font-mono text-sm text-muted">{{ $type->label() }} · {{ $adjustment->created_at?->translatedFormat($dateFormat) }}</p>
            <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ $adjustment->number }}</h2>
            <p class="mt-1 text-sm text-ink">{{ $adjustment->warehouse?->name }}@if ($adjustment->delivery) · <a href="{{ route('delivery-notes.show', $adjustment->delivery) }}" wire:navigate class="link">{{ $adjustment->delivery->number }}</a>@endif</p>
            <nav aria-label="{{ __('stock_adjustments.progress') }}" class="mt-3 flex flex-wrap items-center gap-2 text-sm" data-stock-adjustment-stepper>
                <ol class="flex flex-wrap items-center gap-2">
                    @foreach ($steps as $index => $step)
                        @php
                            $state = $reached < 0 ? 'todo' : ($index < $reached ? 'done' : ($index === $reached ? 'current' : 'todo'));
                        @endphp
                        <li class="flex items-center gap-2" @if ($state === 'current') aria-current="step" @endif data-step="{{ $step->value }}" data-state="{{ $state }}">
                            <span @class([
                                'inline-flex size-6 items-center justify-center rounded-full border text-xs font-semibold',
                                'border-brand-700 bg-brand-700 text-white' => $state === 'current',
                                'border-ink bg-ink text-white' => $state === 'done',
                                'border-line text-muted' => $state === 'todo',
                            ])>@if ($state === 'done')<x-icon name="check" class="size-3" />@else{{ $index + 1 }}@endif</span>
                            <span @class(['font-semibold text-ink' => $state === 'current', 'text-ink' => $state === 'done', 'text-muted' => $state === 'todo'])>{{ $step->label() }}</span>
                            @if (! $loop->last)<span class="text-muted" aria-hidden="true">&rarr;</span>@endif
                        </li>
                    @endforeach
                </ol>
                @if ($off) <x-stock.stock-adjustment-status :status="$adjustment->status" /> @endif
            </nav>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach ($this->moves as $to)
                @continue(in_array($to, $noteMoves, true))
                <button type="button" wire:click="move('{{ $to->value }}')" wire:loading.attr="disabled" class="btn {{ $to === \App\Enums\StockAdjustmentStatus::Draft ? 'btn-secondary' : 'btn-primary' }}" data-action="{{ $to->value }}">{{ __('stock_adjustments.moves.'.$to->value) }}</button>
            @endforeach
        </div>
    </header>

    @php
        $decisionMoves = array_values(array_filter($this->moves, fn ($to) => in_array($to, $noteMoves, true)));
    @endphp
    @if ($decisionMoves !== [])
        <section class="card flex flex-col gap-2 p-5 sm:flex-row sm:items-end" data-decision-moves>
            <div class="grow">
                <label for="move-note" class="form-label">{{ __('stock_adjustments.move_note') }}</label>
                <input wire:model="moveNote" id="move-note" type="text" class="form-input" maxlength="500">
            </div>
            @foreach ($decisionMoves as $to)
                <button type="button" wire:click="move('{{ $to->value }}')" @if ($to === \App\Enums\StockAdjustmentStatus::Cancelled) wire:confirm="{{ __('stock_adjustments.cancel_confirm') }}" @endif class="btn {{ $to === \App\Enums\StockAdjustmentStatus::Posted ? 'btn-primary' : ($to === \App\Enums\StockAdjustmentStatus::Cancelled ? 'btn-danger-outline' : 'btn-secondary') }}" data-action="{{ $to->value }}">{{ __('stock_adjustments.moves.'.$to->value) }}</button>
            @endforeach
        </section>
    @elseif ($adjustment->status === \App\Enums\StockAdjustmentStatus::PendingApproval && in_array(auth()->id(), array_filter([$adjustment->created_by, $adjustment->submitted_by]), true))
        <p class="text-sm text-muted" data-own-adjustment>{{ __('stock_adjustments.own_hint') }}</p>
    @endif

    <section class="card space-y-3 p-5">
        <h3 class="font-semibold text-ink">{{ __('stock_adjustments.fields.reason') }}</h3>
        @if ($editable)
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                <div class="grow">
                    <input wire:model="reasonField" id="reason-field" type="text" class="form-input" maxlength="500" aria-label="{{ __('stock_adjustments.fields.reason') }}">
                </div>
                <button type="button" wire:click="saveReason" class="btn btn-secondary">{{ __('common.save') }}</button>
            </div>
        @else
            <p class="text-sm text-ink">{{ $adjustment->reason }}</p>
        @endif
        @if ($adjustment->decision_note)
            <p class="text-xs text-muted">{{ $adjustment->decider?->name }}: {{ $adjustment->decision_note }}</p>
        @endif
    </section>

    <section class="card space-y-4 p-5" data-adjustment-lines>
        <h3 class="font-semibold text-ink">{{ __('stock_adjustments.lines') }}</h3>
        <ul class="divide-y divide-line text-sm">
            @forelse ($adjustment->lines as $line)
                <li class="flex items-center justify-between gap-3 py-2" wire:key="sal-{{ $line->id }}">
                    <div>
                        <span class="font-mono font-medium text-ink">{{ $this->lineLabel($line) }}</span>
                        <span class="block text-xs text-muted">{{ $line->item?->sku }} {{ $line->item?->name }}@if ($line->old_serial_outcome) · {{ __('stock_adjustments.outcomes.'.$line->old_serial_outcome) }}@endif @if ($line->note) · {{ $line->note }}@endif</span>
                    </div>
                    @if ($editable)
                        <button type="button" wire:click="removeLine({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('stock.receipts.remove_line') }}</button>
                    @endif
                </li>
            @empty
                <li class="py-4"><x-empty-state :title="__('stock_adjustments.no_lines')" icon="items" /></li>
            @endforelse
        </ul>

        @if ($editable)
            @if ($type === \App\Enums\StockAdjustmentType::SerialCorrection)
                <form wire:submit="addCorrection" class="grid gap-3 border-t border-line pt-4 sm:grid-cols-4" novalidate data-correction-form>
                    <div class="sm:col-span-2">
                        <label for="row-id" class="form-label">{{ __('stock_adjustments.fields.old_serial') }}</label>
                        <select wire:model="rowId" id="row-id" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->deliverySerials as $row)
                                <option value="{{ $row->id }}">{{ $row->serial_number }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="new-serial" class="form-label">{{ __('stock_adjustments.fields.new_serial') }}</label>
                        <input wire:model="newSerial" id="new-serial" type="text" class="form-input font-mono" maxlength="100">
                    </div>
                    <div>
                        <label for="outcome" class="form-label">{{ __('stock_adjustments.fields.outcome') }}</label>
                        <select wire:model="outcome" id="outcome" class="form-input">
                            @foreach (\App\Actions\StockAdjustment\ManageStockAdjustment::OUTCOMES as $value)
                                <option value="{{ $value }}">{{ __('stock_adjustments.outcomes.'.$value) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex justify-end sm:col-span-4"><button type="submit" class="btn btn-primary">{{ __('stock_adjustments.add_line') }}</button></div>
                </form>
            @else
                <div class="space-y-3 border-t border-line pt-4" data-item-picker>
                    @if ($this->selectedItem)
                        <p class="text-sm text-ink" data-selected-item><span class="font-medium">{{ $this->selectedItem->sku }} {{ $this->selectedItem->name }}</span> <button type="button" wire:click="$set('itemId', null)" class="link text-xs">{{ __('common.cancel') }}</button></p>
                        @if ($type === \App\Enums\StockAdjustmentType::Found)
                            <form wire:submit="addFound" class="flex flex-col gap-2 sm:flex-row sm:items-end" novalidate data-found-form>
                                <div class="grow">
                                    <label for="serial-input" class="form-label">{{ __('stock_adjustments.fields.serial') }}</label>
                                    <input wire:model="serialInput" id="serial-input" type="text" class="form-input font-mono" maxlength="100">
                                </div>
                                <button type="submit" class="btn btn-primary">{{ __('stock_adjustments.add_line') }}</button>
                            </form>
                        @else
                            <form wire:submit="addQuantity" class="grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-end" novalidate data-quantity-form>
                                <div>
                                    <label for="quantity-input" class="form-label">{{ __('stock_adjustments.fields.quantity') }}</label>
                                    <input wire:model="quantityInput" id="quantity-input" type="text" inputmode="decimal" class="form-input" placeholder="-2">
                                </div>
                                <div>
                                    <label for="line-note" class="form-label">{{ __('stock_adjustments.fields.note') }}</label>
                                    <input wire:model="lineNote" id="line-note" type="text" class="form-input" maxlength="500">
                                </div>
                                <button type="submit" class="btn btn-primary">{{ __('stock_adjustments.add_line') }}</button>
                            </form>
                        @endif
                    @else
                        <div>
                            <label for="item-search" class="form-label">{{ __('stock_adjustments.fields.item') }}</label>
                            <input wire:model.live.debounce.300ms="itemSearch" id="item-search" type="search" class="form-input" placeholder="{{ __('stock_adjustments.item_search') }}">
                        </div>
                        @if ($this->itemOptions->isNotEmpty())
                            <ul class="divide-y divide-line rounded-md border border-line text-sm">
                                @foreach ($this->itemOptions as $option)
                                    <li wire:key="io-{{ $option->id }}"><button type="button" wire:click="pickItem({{ $option->id }})" class="block w-full px-3 py-2 text-left hover:bg-brand-50">{{ $option->sku }} · {{ $option->name }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                </div>
            @endif
        @endif
    </section>

    <section class="card p-5" data-stock-adjustment-history>
        <h3 class="font-semibold text-ink">{{ __('stock_adjustments.history') }}</h3>
        <ol class="mt-2 divide-y divide-line">
            @foreach ($adjustment->transitions as $transition)
                <li class="py-2 text-sm" wire:key="sat-{{ $transition->id }}"><span class="font-semibold text-ink">{{ \App\Enums\StockAdjustmentStatus::tryFrom($transition->to_status)?->label() ?? $transition->to_status }}</span> <span class="text-muted">· {{ $transition->user?->name ?? __('activity.system') }} · {{ $transition->created_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</span>@if ($transition->reasonLabel())<span class="block text-xs text-muted">{{ $transition->reasonLabel() }}</span>@endif</li>
            @endforeach
        </ol>
    </section>
</div>
