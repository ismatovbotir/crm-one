<div class="space-y-6">
    @php
        $canSeeValue = auth()->user()->can('viewValue', \App\Models\StockOffAct::class);
        $act = $this->act;
        $editable = auth()->user()->can('update', $act);
        $dateFormat = \App\Enums\Locale::current()->dateFormat();
        $steps = \App\Enums\StockOffStatus::steps();
        $off = in_array($act->status, [\App\Enums\StockOffStatus::Rejected, \App\Enums\StockOffStatus::Cancelled], true);
        $reached = $off ? -1 : array_search($act->status, $steps, true);
        $noteMoves = [\App\Enums\StockOffStatus::Approved, \App\Enums\StockOffStatus::Rejected, \App\Enums\StockOffStatus::Cancelled];
    @endphp

    <a href="{{ route('stock-off.index') }}" wire:navigate class="link text-sm">&larr; {{ __('stock_off.back') }}</a>

    <x-flash />

    <header class="card flex flex-col gap-4 p-6 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <p class="font-mono text-sm text-muted">{{ $act->reason->label() }} · {{ $act->created_at?->translatedFormat($dateFormat) }}</p>
            <h2 class="text-2xl font-semibold tracking-tight text-ink">{{ $act->number }}</h2>
            <p class="mt-1 text-sm text-ink">{{ $act->warehouse?->name }}@if ($act->goodsReceipt) · <a href="{{ route('goods-receipts.show', $act->goodsReceipt) }}" wire:navigate class="link">{{ $act->goodsReceipt->number }}</a>@endif @if ($act->supplierPartner) · {{ $act->supplierPartner->name }}@endif</p>
            <p class="mt-1 text-xs text-muted" data-approval-level="{{ $act->approval_level }}">
                {{ __('stock_off.approval_level.'.$act->approval_level) }}
                @can('viewValue', \App\Models\StockOffAct::class)
                    @foreach ($act->totals ?? [] as $currency => $minor)
                        · {{ \App\Support\Money::of((int) $minor, $currency)->format() }}
                    @endforeach
                @endcan
            </p>
            <nav aria-label="{{ __('stock_off.progress') }}" class="mt-3 flex flex-wrap items-center gap-2 text-sm" data-stock-off-stepper>
                <ol class="flex flex-wrap items-center gap-2">
                    @foreach ($steps as $index => $step)
                        @php
                            $state = $reached === false || $reached < 0 ? 'todo' : ($index < $reached ? 'done' : ($index === $reached ? 'current' : 'todo'));
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
                @if ($off) <x-stock.stock-off-status :status="$act->status" /> @endif
            </nav>
        </div>
        <div class="flex flex-wrap gap-2">
            @can('print', $act)
                <a href="{{ route('stock-off.print', $act) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('stock.actions.print') }}</a>
            @endcan
            @foreach ($this->moves as $to)
                @continue(in_array($to, $noteMoves, true))
                <button type="button" wire:click="move('{{ $to->value }}')" wire:loading.attr="disabled" class="btn {{ $to === \App\Enums\StockOffStatus::Draft ? 'btn-secondary' : 'btn-primary' }}" data-action="{{ $to->value }}">{{ __('stock_off.moves.'.$to->value) }}</button>
            @endforeach
        </div>
    </header>

    @php
        $decisionMoves = array_values(array_filter($this->moves, fn ($to) => in_array($to, $noteMoves, true)));
    @endphp
    @if ($decisionMoves !== [])
        <section class="card flex flex-col gap-2 p-5 sm:flex-row sm:items-end" data-decision-moves>
            <div class="grow">
                <label for="move-note" class="form-label">{{ __('stock_off.move_note') }}</label>
                <input wire:model="moveNote" id="move-note" type="text" class="form-input" maxlength="500">
            </div>
            @foreach ($decisionMoves as $to)
                <button type="button" wire:click="move('{{ $to->value }}')" @if ($to === \App\Enums\StockOffStatus::Cancelled) wire:confirm="{{ __('stock_off.cancel_confirm') }}" @endif class="btn {{ $to === \App\Enums\StockOffStatus::Approved ? 'btn-primary' : ($to === \App\Enums\StockOffStatus::Cancelled ? 'btn-danger-outline' : 'btn-secondary') }}" data-action="{{ $to->value }}">{{ __('stock_off.moves.'.$to->value) }}</button>
            @endforeach
        </section>
    @elseif ($act->status === \App\Enums\StockOffStatus::Submitted && in_array(auth()->id(), array_filter([$act->created_by, $act->submitted_by]), true))
        <p class="text-sm text-muted" data-own-act>{{ __('stock_off.own_act_hint') }}</p>
    @endif

    {{-- Standard document tabs (user decision 2026-10-20): Header | Items | Other. --}}
    <x-tabs :tabs="$this->documentTabList()" :active="$tab" property="tab" prefix="doc" :label="__('common.document_tabs.label')">
        @if ($tab === 'header')
            <div class="space-y-6" data-stock-off-header>
                <section class="card grid gap-4 p-5 sm:grid-cols-[1fr_2fr_auto] sm:items-end">
                    <div>
                        <label for="so-reason" class="form-label">{{ __('stock_off.fields.reason') }}</label>
                        <select wire:model="reasonField" id="so-reason" class="form-input" @disabled(! $editable)>
                            @foreach ($reasons as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="so-note" class="form-label">{{ __('stock_off.fields.note') }}</label>
                        <input wire:model="noteField" id="so-note" type="text" class="form-input" maxlength="2000" @disabled(! $editable)>
                    </div>
                    @if ($editable)
                        <button type="button" wire:click="saveHeader" class="btn btn-secondary">{{ __('common.save') }}</button>
                    @endif
                    @if ($act->decision_note)
                        <p class="text-sm text-muted sm:col-span-3">{{ __('stock_off.decision', ['user' => $act->decider?->name ?? '—', 'note' => $act->decision_note]) }}</p>
                    @endif
                </section>
                @if ($act->goods_receipt_id !== null)
                    <section class="card space-y-3 p-5" data-claim>
                        <h3 class="font-semibold text-ink">{{ __('stock_off.claim_title') }}</h3>
                        <p class="text-sm text-muted">{{ __('stock_off.claim_status.'.$act->claim_status) }}@if ($act->claim_ref) · {{ $act->claim_ref }}@endif @if ($act->claim_settled_at) · {{ $act->claim_settled_at->translatedFormat($dateFormat) }}@endif</p>
                        @can('manageClaim', $act)
                            <div class="grid gap-3 sm:grid-cols-[1fr_1fr_2fr_auto] sm:items-end">
                                <div>
                                    <label for="claim-status" class="form-label">{{ __('stock_off.fields.claim_status') }}</label>
                                    <select wire:model="claimForm.claim_status" id="claim-status" class="form-input">
                                        @foreach (\App\Models\StockOffAct::CLAIM_STATUSES as $value)
                                            <option value="{{ $value }}">{{ __('stock_off.claim_status.'.$value) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="claim-ref" class="form-label">{{ __('stock_off.fields.claim_ref') }}</label>
                                    <input wire:model="claimForm.claim_ref" id="claim-ref" type="text" class="form-input" maxlength="60">
                                </div>
                                <div>
                                    <label for="claim-note" class="form-label">{{ __('stock_off.fields.claim_note') }}</label>
                                    <input wire:model="claimForm.claim_note" id="claim-note" type="text" class="form-input" maxlength="2000">
                                </div>
                                <button type="button" wire:click="saveClaim" class="btn btn-secondary" data-action="save-claim">{{ __('common.save') }}</button>
                            </div>
                        @endcan
                    </section>
                @endif
            </div>
        @elseif ($tab === 'items')
            <div class="space-y-6">
                @if ($editable)
                    <section class="card p-5">
                        <label for="so-item" class="form-label">{{ __('stock.receipts.add_item') }}</label>
                        <input wire:model.live.debounce.300ms="itemSearch" id="so-item" type="search" class="form-input" placeholder="{{ __('stock.receipts.item_search') }}">
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

                @if ($results !== [])
                    <ul class="space-y-1 text-xs" data-pick-results>
                        @foreach ($results as $result)
                            <li wire:key="pr-{{ $loop->index }}" class="flex gap-2 {{ $result['result'] === 'ok' ? 'text-ink' : 'text-brand-700' }}"><x-icon :name="$result['result'] === 'ok' ? 'check' : 'warning'" class="size-3.5 shrink-0" /><span class="font-mono">{{ $result['serial'] }}</span>@if ($result['message'] !== '') <span>— {{ $result['message'] }}</span>@endif</li>
                        @endforeach
                    </ul>
                @endif

                @forelse ($act->lines as $line)
                    <section class="card space-y-3 p-5" wire:key="sol-{{ $line->id }}" data-stock-off-line="{{ $line->id }}">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <p class="font-semibold text-ink">{{ $line->position }}. {{ $line->item?->name }} <span class="font-mono text-xs text-muted">{{ $line->item?->sku }}</span> @if ($line->is_serialized) <x-badge tone="outline">{{ __('orders.lines.serialized') }}</x-badge> @endif</p>
                            <p class="text-sm text-ink">{{ __('stock_off.fields.quantity') }}: <span class="font-semibold">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }}</span>@if ($line->value_minor !== null && $canSeeValue) · {{ \App\Support\Money::of($line->value_minor, (string) $line->currency)->format() }}@endif</p>
                        </div>
                        @if ($line->note) <p class="text-xs text-muted">{{ $line->note }}</p> @endif
                        @if ($line->is_serialized)
                            @if ($line->serials->isNotEmpty())
                                <ol class="flex flex-wrap gap-1 text-xs" data-stock-off-serials>
                                    @foreach ($line->serials as $row)
                                        <li class="flex items-center gap-1 rounded border border-line px-2 py-0.5 font-mono" wire:key="sos-{{ $row->id }}">{{ $row->serial_number }}
                                            @if ($editable)<button type="button" wire:click="removeSerial({{ $row->id }})" class="text-muted hover:text-brand-700" aria-label="{{ __('stock.entry.remove', ['serial' => $row->serial_number]) }}"><x-icon name="close" class="size-3" /></button>@endif
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                            @if ($editable)
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <label for="sn-{{ $line->id }}" class="sr-only">{{ __('stock_off.pick_serials') }}</label>
                                    <textarea wire:model="serialInput.{{ $line->id }}" id="sn-{{ $line->id }}" rows="2" class="form-input font-mono text-xs" placeholder="{{ __('stock_off.pick_serials') }}"></textarea>
                                    <button type="button" wire:click="addSerials({{ $line->id }})" class="btn btn-secondary btn-sm shrink-0">{{ __('stock.entry.add') }}</button>
                                </div>
                            @endif
                        @elseif ($editable)
                            <div class="flex items-end gap-2">
                                <div>
                                    <label for="q-{{ $line->id }}" class="text-xs text-muted">{{ __('stock_off.fields.quantity') }}</label>
                                    <input wire:model="quantities.{{ $line->id }}" id="q-{{ $line->id }}" type="text" inputmode="decimal" class="form-input">
                                </div>
                                <button type="button" wire:click="saveQuantity({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                            </div>
                        @endif
                        @if ($editable)
                            <button type="button" wire:click="removeLine({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('stock.receipts.remove_line') }}</button>
                        @endif
                    </section>
                @empty
                    <x-empty-state :title="__('stock.receipts.no_lines')" icon="items" />
                @endforelse
            </div>
        @else
            <div class="space-y-6">
                <section class="card space-y-3 p-5" data-evidence>
                    <h3 class="font-semibold text-ink">{{ __('stock_off.fields.evidence') }}</h3>
                    <ul class="divide-y divide-line text-sm">
                        @forelse ($act->attachments as $file)
                            <li class="py-1.5" wire:key="ev-{{ $file->id }}"><a href="{{ route('attachments.download', $file) }}" target="_blank" rel="noopener" class="link">{{ $file->original_name }}</a></li>
                        @empty
                            <li class="py-1.5 text-muted">{{ __('stock_off.no_evidence') }}</li>
                        @endforelse
                    </ul>
                    @can('addEvidence', $act)
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div class="grow">
                                <label for="evidence" class="form-label">{{ __('stock_off.evidence_upload') }}</label>
                                <input wire:model="evidence" id="evidence" type="file" accept=".pdf,.jpg,.jpeg,.png" class="form-input">
                                @error('evidence') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="uploadEvidence" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="evidence,uploadEvidence">{{ __('stock_off.upload') }}</button>
                        </div>
                    @endcan
                </section>
                <section class="card p-5" data-stock-off-history>
                    <h3 class="font-semibold text-ink">{{ __('stock_off.history') }}</h3>
                    <ol class="mt-2 divide-y divide-line">
                        @foreach ($act->transitions as $transition)
                            <li class="py-2 text-sm" wire:key="sot-{{ $transition->id }}"><span class="font-semibold text-ink">{{ \App\Enums\StockOffStatus::tryFrom($transition->to_status)?->label() ?? $transition->to_status }}</span> <span class="text-muted">· {{ $transition->user?->name ?? __('activity.system') }} · {{ $transition->created_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</span>@if ($transition->reasonLabel())<span class="block text-xs text-muted">{{ $transition->reasonLabel() }}</span>@endif</li>
                        @endforeach
                    </ol>
                </section>
            </div>
        @endif
    </x-tabs>
</div>
