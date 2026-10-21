<div class="space-y-4" data-item-serials>
    @php($item = $this->item)
    @if (! $item->is_serialized)
        <div class="p-6" data-serials-off>
            <p class="font-semibold text-ink">{{ __('stock.serials.tracking_off') }}</p>
            <p class="mt-1 text-sm text-muted">{{ __('stock.serials.tracking_off_hint') }}</p>
            @can('updateSerialTracking', $item)
                <button type="button" wire:click="toggleTracking" wire:confirm="{{ __('stock.serials.tracking_on_confirm') }}" class="btn btn-secondary mt-3" data-action="enable-tracking">{{ __('stock.serials.tracking_on') }}</button>
            @else
                <p class="mt-2 text-xs text-muted">{{ __('stock.serials.tracking_who') }}</p>
            @endcan
        </div>
    @else
        <div class="flex flex-col gap-3 border-b border-line px-6 py-4 lg:flex-row lg:items-center lg:justify-between">
            <p class="flex flex-wrap gap-3 text-sm text-muted" data-serial-counts>
                @foreach ($statuses as $serialStatus)
                    @if (($this->counts[$serialStatus->value] ?? 0) > 0)
                        <span>{{ $serialStatus->label() }}: <span class="font-semibold text-ink">{{ $this->counts[$serialStatus->value] }}</span></span>
                    @endif
                @endforeach
                @if ($this->counts->isEmpty()) {{ __('stock.serials.none_on_item') }} @endif
            </p>
            <div class="flex flex-wrap items-center gap-2">
                @if ($this->canRegister)
                    <livewire:stock.serial-registration :item-id="$item->id" :item-locked="true" :key="'sr-item-'.$item->id" />
                    <a href="{{ route('items.serials.template', $item) }}" class="btn btn-secondary" data-action="serial-template">{{ __('stock.serials.template') }}</a>
                @else
                    <p class="text-sm text-muted" data-serials-readonly>{{ __('stock.serials.entry_by_stock') }}</p>
                @endif
                @if ($this->canList)
                    <a href="{{ route('items.serials.export', $item) }}" class="btn btn-secondary" data-action="serial-export">{{ __('stock.serials.export') }}</a>
                @endif
            </div>
        </div>

        @if ($this->canList)
            <div class="flex flex-wrap items-center gap-3 px-6" role="search">
                <div class="min-w-56 flex-1">
                    <label for="is-search" class="sr-only">{{ __('common.search') }}</label>
                    <input wire:model.live.debounce.300ms="search" id="is-search" type="search" class="form-input font-mono" placeholder="{{ __('stock.serials.search_placeholder') }}">
                </div>
                <div>
                    <label for="is-status" class="sr-only">{{ __('common.status') }}</label>
                    <select wire:model.live="status" id="is-status" class="form-input">
                        <option value="">{{ __('stock.serials.not_written_off') }}</option>
                        @foreach ($statuses as $serialStatus)
                            <option value="{{ $serialStatus->value }}">{{ $serialStatus->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($this->canRegister)
                <form wire:submit="moveSelected" class="mx-6 flex flex-wrap items-end gap-3 rounded-md border border-line p-3" data-bulk-move novalidate>
                    <p class="w-full text-xs text-muted">{{ __('stock.serials.bulk.hint', ['count' => count($selected)]) }}</p>
                    <div>
                        <label for="bm-wh" class="form-label">{{ __('stock.serials.bulk.warehouse') }}</label>
                        <select wire:model="moveWarehouseId" id="bm-wh" class="form-input">
                            <option value="">—</option>
                            @foreach ($this->warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach
                        </select>
                        @error('moveWarehouseId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="min-w-56 flex-1">
                        <label for="bm-reason" class="form-label">{{ __('stock.serials.edit.reason') }}</label>
                        <input wire:model="moveReason" id="bm-reason" type="text" maxlength="255" class="form-input">
                        @error('moveReason') <p class="form-error">{{ $message }}</p> @enderror
                        @error('selected') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-secondary">{{ __('stock.serials.bulk.move') }}</button>
                    <p class="w-full text-xs text-muted">{{ __('stock.serials.bulk.write_off_hint') }}</p>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm" data-serial-list>
                    <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                        <tr>
                            @if ($this->canRegister)<th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('stock.serials.bulk.selected') }}</span></th>@endif
                            <th scope="col" class="px-4 py-2">{{ __('stock.serials.serial') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('common.status') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('stock.serials.warehouse') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('stock.serials.warranty_until') }}</th>
                            <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($this->serials as $serial)
                            @php($editable = in_array($serial->status, \App\Actions\Stock\CorrectSerial::EDITABLE, true))
                            <tr wire:key="isr-{{ $serial->id }}" data-serial="{{ $serial->serial_normalized }}">
                                @if ($this->canRegister)
                                    <td class="px-4 py-2">
                                        @if ($serial->status === \App\Enums\SerialStatus::Available)
                                            <input type="checkbox" wire:model.live="selected" value="{{ $serial->id }}" class="rounded border-line" aria-label="{{ __('stock.serials.bulk.select', ['serial' => $serial->serial_normalized]) }}">
                                        @endif
                                    </td>
                                @endif
                                <td class="px-4 py-2 font-mono"><a href="{{ route('serials.show', $serial) }}" wire:navigate class="link">{{ $serial->serial_normalized }}</a></td>
                                <td class="px-4 py-2 text-ink">{{ $serial->status->label() }}</td>
                                <td class="px-4 py-2 text-muted">{{ $serial->warehouse?->name ?? '—' }}</td>
                                <td class="px-4 py-2 text-muted">{{ $serial->warranty_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($this->canRegister && $editable)
                                        <button type="button" wire:click="startEdit({{ $serial->id }})" class="btn btn-secondary btn-sm" data-action="edit-serial">{{ __('common.edit') }}</button>
                                    @elseif ($this->canRegister)
                                        <span class="text-xs text-muted">{{ __('stock.serials.edit.locked') }}</span>
                                    @endif
                                </td>
                            </tr>
                            @if ($editingId === $serial->id)
                                <tr wire:key="isr-edit-{{ $serial->id }}">
                                    <td colspan="6" class="bg-sidebar px-4 py-3">
                                        <form wire:submit="saveEdit" class="grid gap-3 sm:grid-cols-5" data-serial-edit novalidate>
                                            <div>
                                                <label for="se-serial" class="form-label">{{ __('stock.serials.serial') }}</label>
                                                <input wire:model="edit.serial" id="se-serial" type="text" maxlength="100" class="form-input font-mono">
                                                @error('edit.serial') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                            </div>
                                            <div>
                                                <label for="se-wh" class="form-label">{{ __('stock.serials.warehouse') }}</label>
                                                <select wire:model="edit.warehouse_id" id="se-wh" class="form-input">
                                                    @foreach ($this->warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label for="se-wu" class="form-label">{{ __('stock.serials.warranty_until') }}</label>
                                                <input wire:model="edit.warranty_until" id="se-wu" type="date" class="form-input">
                                            </div>
                                            <div>
                                                <label for="se-note" class="form-label">{{ __('stock.serials.edit.note') }}</label>
                                                <input wire:model="edit.note" id="se-note" type="text" maxlength="255" class="form-input">
                                            </div>
                                            <div>
                                                <label for="se-reason" class="form-label">{{ __('stock.serials.edit.reason') }}</label>
                                                <input wire:model="edit.reason" id="se-reason" type="text" maxlength="255" class="form-input">
                                                @error('edit.reason') <p class="form-error">{{ $message }}</p> @enderror
                                            </div>
                                            <div class="flex justify-end gap-2 sm:col-span-5">
                                                <button type="button" wire:click="cancelEdit" class="btn btn-secondary btn-sm">{{ __('common.cancel') }}</button>
                                                <button type="submit" class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="6" class="px-4 py-4 text-sm text-muted">{{ __('stock.serials.none_on_item') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-6 pb-4">{{ $this->serials->links() }}</div>
        @else
            <p class="px-6 pb-4 text-sm text-muted">{{ __('stock.serials.list_restricted') }}</p>
        @endif

        @if ($this->canList)
            <section class="border-t border-line px-6 py-4" aria-labelledby="is-history" data-import-history>
                <h4 id="is-history" class="font-semibold text-ink">{{ __('stock.serials.history_title') }}</h4>
                @if ($this->history->isEmpty())
                    <p class="mt-1 text-sm text-muted">{{ __('stock.serials.history_empty') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($this->history as $receipt)
                            <li class="flex flex-wrap justify-between gap-2 py-2" wire:key="ih-{{ $receipt->id }}">
                                <span class="text-ink"><a href="{{ route('goods-receipts.show', $receipt) }}" wire:navigate class="link font-mono">{{ $receipt->number }}</a> · {{ $receipt->creator?->name ?? '—' }}</span>
                                <span class="text-muted">{{ __('stock.serials.history_rows', ['ok' => \App\Support\Quantity::trimmed((string) $receipt->lines->sum('received_qty')), 'errors' => 0]) }} · {{ $receipt->created_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif
    @endif
</div>
