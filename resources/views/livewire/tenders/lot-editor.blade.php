<div class="space-y-6" data-lot-editor>
    @php($tender = $this->tender)
    <div class="flex flex-wrap items-center gap-2" x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('tenders.lots.title') }}">
        @foreach ($tender->lots as $lotOption)
            <button type="button" role="tab" aria-selected="{{ $lotOption->id === $lotId ? 'true' : 'false' }}" wire:click="selectLot({{ $lotOption->id }})" wire:key="lot-tab-{{ $lotOption->id }}"
                    @class(['rounded-md border px-3 py-1.5 text-sm font-semibold', 'border-brand-600 bg-brand-50 text-ink' => $lotOption->id === $lotId, 'border-line text-muted hover:text-ink' => $lotOption->id !== $lotId])>{{ __('tenders.lot_short', ['number' => $lotOption->number]) }} · {{ \Illuminate\Support\Str::limit($lotOption->title, 30) }}</button>
        @endforeach
        @if ($editable)
            <form wire:submit="addLot" class="ml-auto flex items-end gap-2">
                <label for="new-lot" class="sr-only">{{ __('tenders.lots.title') }}</label>
                <input wire:model="newLotTitle" id="new-lot" type="text" class="form-input" placeholder="{{ __('tenders.lots.new_placeholder') }}">
                <button type="submit" class="btn btn-secondary">{{ __('tenders.lots.add') }}</button>
            </form>
        @endif
    </div>
    @error('newLotTitle') <p class="form-error">{{ $message }}</p> @enderror

    @if ($this->lot)
        <form wire:submit="saveLot" class="grid gap-3 sm:grid-cols-[1fr_2fr_auto]">
            <div><label for="lot-title" class="form-label">{{ __('tenders.lots.title') }}</label><input wire:model="lotTitle" id="lot-title" type="text" class="form-input" @disabled(! $editable)></div>
            <div><label for="lot-desc" class="form-label">{{ __('tenders.lots.description') }}</label><input wire:model="lotDescription" id="lot-desc" type="text" class="form-input" @disabled(! $editable)></div>
            @if ($editable)
                <div class="flex items-end gap-2">
                    <button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button>
                    @if ($tender->lots->count() > 1)<button type="button" wire:click="removeLot" wire:confirm="{{ __('tenders.lots.confirm_remove') }}" class="btn btn-danger-outline">{{ __('tenders.remove') }}</button>@endif
                </div>
            @endif
        </form>

        @if ($isTransport)
            <form wire:submit="saveSpec" class="space-y-6" novalidate data-transport-spec>
                @foreach ($sections as $section => $fields)
                    <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @disabled(! $editable) wire:key="sec-{{ $section }}">
                        <legend class="mb-2 text-sm font-semibold text-ink">{{ __('tenders.spec_sections.'.$section) }}</legend>
                        @foreach ($fields as $field => $type)
                            @php([$kind, $option] = array_pad(explode(':', $type), 2, null))
                            <div @class(['sm:col-span-2 lg:col-span-3' => in_array($kind, ['textarea', 'payer', 'multi'], true)]) wire:key="f-{{ $field }}">
                                @if ($kind === 'bool')
                                    <label class="flex items-center gap-2 pt-6 text-sm text-ink"><input wire:model="spec.{{ $field }}" type="checkbox" class="rounded border-line"> {{ __('tenders.spec.'.$field) }}</label>
                                @elseif ($kind === 'multi')
                                    <fieldset>
                                        <legend class="form-label">{{ __('tenders.spec.'.$field) }}</legend>
                                        <div class="flex flex-wrap gap-x-4 gap-y-2">
                                            @foreach (config('tenders.vocab.'.$option) as $value)
                                                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="spec.{{ $field }}" value="{{ $value }}" type="checkbox" class="rounded border-line"> {{ __('tenders.vocab.'.$option.'.'.$value) }}</label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @elseif ($kind === 'payer')
                                    <fieldset>
                                        <legend class="form-label">{{ __('tenders.spec.charges_payer') }}</legend>
                                        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                            @foreach (config('tenders.vocab.payer_charges') as $charge)
                                                <div>
                                                    <label for="payer-{{ $charge }}" class="text-xs text-muted">{{ __('tenders.vocab.payer_charges.'.$charge) }}</label>
                                                    <select wire:model="spec.charges_payer.{{ $charge }}" id="payer-{{ $charge }}" class="form-input">
                                                        <option value="">{{ __('tenders.unanswered') }}</option>
                                                        @foreach (config('tenders.vocab.payer') as $payer) <option value="{{ $payer }}">{{ __('tenders.vocab.payer.'.$payer) }}</option> @endforeach
                                                    </select>
                                                </div>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @else
                                    <label for="spec-{{ $field }}" class="form-label">{{ __('tenders.spec.'.$field) }}</label>
                                    @if ($kind === 'textarea')
                                        <textarea wire:model="spec.{{ $field }}" id="spec-{{ $field }}" rows="2" class="form-input"></textarea>
                                    @elseif ($kind === 'select')
                                        <select wire:model="spec.{{ $field }}" id="spec-{{ $field }}" class="form-input">
                                            <option value="">—</option>
                                            @foreach (config('tenders.vocab.'.$option) as $value) <option value="{{ $value }}">{{ __('tenders.vocab.'.$option.'.'.$value) }}</option> @endforeach
                                        </select>
                                    @elseif ($kind === 'tri')
                                        <select wire:model="spec.{{ $field }}" id="spec-{{ $field }}" class="form-input">
                                            <option value="">{{ __('tenders.unanswered') }}</option><option value="1">{{ __('common.yes') }}</option><option value="0">{{ __('common.no') }}</option>
                                        </select>
                                    @elseif ($kind === 'incoterm')
                                        <select wire:model="spec.{{ $field }}" id="spec-{{ $field }}" class="form-input">
                                            <option value="">—</option>
                                            @foreach ($incoterms as $value => $label) <option value="{{ $value }}">{{ $value }} — {{ $label }}</option> @endforeach
                                        </select>
                                    @elseif ($kind === 'currency')
                                        <select wire:model="spec.{{ $field }}" id="spec-{{ $field }}" class="form-input">
                                            <option value="">—</option>
                                            @foreach (config('tenders.currencies') as $currency) <option value="{{ $currency }}">{{ $currency }}</option> @endforeach
                                        </select>
                                    @elseif ($kind === 'date')
                                        <input wire:model="spec.{{ $field }}" id="spec-{{ $field }}" type="date" class="form-input">
                                    @elseif ($kind === 'int')
                                        <input wire:model="spec.{{ $field }}" id="spec-{{ $field }}" type="number" min="0" class="form-input">
                                    @else
                                        <input wire:model="spec.{{ $field }}" id="spec-{{ $field }}" type="text" @if (in_array($kind, ['decimal', 'money', 'percent'], true)) inputmode="decimal" @endif @if ($kind === 'country') maxlength="2" @endif class="form-input">
                                    @endif
                                    @if ($kind === 'list')<p class="mt-1 text-xs text-muted">{{ __('tenders.list_hint') }}</p>@endif
                                @endif
                                @error('spec.'.$field) <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </fieldset>
                @endforeach
                @if ($editable)
                    <div class="flex justify-end"><button type="submit" class="btn btn-primary" data-action="save-spec">{{ __('common.save') }}</button></div>
                @endif
            </form>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm" data-tender-items>
                    <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                        <tr><th scope="col" class="py-2">#</th><th scope="col" class="py-2">{{ __('tenders.items.name') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.items.quantity') }}</th><th scope="col" class="py-2">{{ __('tenders.items.required_by') }}</th><th scope="col" class="py-2 text-right">{{ __('tenders.items.target_price') }}</th><th></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($this->lot->items as $line)
                            <tr wire:key="item-{{ $line->id }}" class="align-top">
                                <td class="py-2 text-muted">{{ $line->position }}</td>
                                <td class="py-2 text-ink"><span class="font-medium">{{ $line->name }}</span>@if ($line->sku) <span class="font-mono text-xs text-muted">{{ $line->sku }}</span>@endif
                                    @if ($line->specification)<span class="block text-xs whitespace-pre-line text-muted">{{ $line->specification }}</span>@endif
                                    @if ($line->alternatives_allowed)<x-badge tone="outline">{{ __('tenders.items.alternatives') }}</x-badge>@endif</td>
                                <td class="py-2 text-right whitespace-nowrap text-ink">{{ \App\Support\Quantity::trimmed((string) $line->quantity) }} {{ $line->unit_code }}</td>
                                <td class="py-2 text-ink">{{ $line->required_by?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                                <td class="py-2 text-right text-muted">{{ $line->target_price_minor !== null ? \App\Support\Money::of($line->target_price_minor, $tender->evaluation_currency)->format() : '—' }}</td>
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if ($editable)
                                        <button type="button" wire:click="editItem({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('common.edit') }}</button>
                                        <button type="button" wire:click="removeItem({{ $line->id }})" class="btn btn-secondary btn-sm">{{ __('tenders.remove') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-sm text-muted">{{ __('tenders.items.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($editable)
                <form wire:submit="saveItem" class="grid gap-3 border-t border-line pt-4 sm:grid-cols-3" novalidate data-item-form>
                    <div class="sm:col-span-3">
                        <label for="item-search" class="form-label">{{ __('tenders.items.catalog_search') }}</label>
                        <input wire:model.live.debounce.300ms="itemSearch" id="item-search" type="search" class="form-input" placeholder="{{ __('tenders.items.catalog_placeholder') }}">
                        @if ($this->itemOptions->isNotEmpty())
                            <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                                @foreach ($this->itemOptions as $option)
                                    <li wire:key="io-{{ $option->id }}"><button type="button" wire:click="pickCatalogItem({{ $option->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-brand-50">{{ $option->name }} <span class="font-mono text-xs text-muted">{{ $option->sku }}</span></button></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="sm:col-span-2"><label for="item-name" class="form-label">{{ __('tenders.items.name') }}</label><input wire:model="item.name" id="item-name" type="text" class="form-input" @disabled($catalogItemId !== null)>@error('item.name') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="item-qty" class="form-label">{{ __('tenders.items.quantity') }}</label><input wire:model="item.quantity" id="item-qty" type="text" inputmode="decimal" class="form-input">@error('item.quantity') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="item-unit" class="form-label">{{ __('tenders.items.unit') }}</label><input wire:model="item.unit_code" id="item-unit" type="text" class="form-input" @disabled($catalogItemId !== null)></div>
                    <div><label for="item-precision" class="form-label">{{ __('tenders.items.precision') }}</label><input wire:model="item.unit_precision" id="item-precision" type="number" min="0" max="3" class="form-input" @disabled($catalogItemId !== null)></div>
                    <div><label for="item-date" class="form-label">{{ __('tenders.items.required_by') }}</label><input wire:model="item.required_by" id="item-date" type="date" class="form-input"></div>
                    <div class="sm:col-span-3"><label for="item-spec" class="form-label">{{ __('tenders.items.specification') }}</label><textarea wire:model="item.specification" id="item-spec" rows="2" class="form-input"></textarea></div>
                    <div><label for="item-hs" class="form-label">{{ __('tenders.items.hs_code') }}</label><input wire:model="item.hs_code" id="item-hs" type="text" class="form-input">@error('item.hs_code') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <div><label for="item-target" class="form-label">{{ __('tenders.items.target_price') }} ({{ $tender->evaluation_currency }})</label><input wire:model="item.target_price" id="item-target" type="text" inputmode="decimal" class="form-input">@error('item.target_price') <p class="form-error">{{ $message }}</p> @enderror</div>
                    <label class="flex items-center gap-2 pt-6 text-sm text-ink"><input wire:model="item.alternatives_allowed" type="checkbox" class="rounded border-line"> {{ __('tenders.items.alternatives') }}</label>
                    <div class="flex justify-end gap-2 sm:col-span-3">
                        @if ($editingItemId || $catalogItemId)<button type="button" wire:click="resetItemForm" class="btn btn-secondary">{{ __('common.cancel') }}</button>@endif
                        <button type="submit" class="btn btn-primary">{{ $editingItemId ? __('common.save') : __('tenders.items.add') }}</button>
                    </div>
                </form>
            @endif
        @endif
    @endif
</div>
