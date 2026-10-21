<div data-serial-registration>
    @if ($canRegister)
        <button type="button" wire:click="openDialog" class="btn btn-primary" data-action="open-serial-registration"><x-icon name="plus" class="size-4" /> {{ __('stock.register.button') }}</button>
    @endif

    @if ($open)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="sr-title" wire:keydown.escape="closeDialog" x-data="modalDialog" @keydown="trap" tabindex="-1">
            <div class="card my-8 w-full max-w-3xl space-y-5 p-6">
                <div class="flex items-start justify-between gap-4">
                    <h2 id="sr-title" class="text-lg font-semibold text-ink">{{ __('stock.register.title') }}</h2>
                    <button type="button" wire:click="closeDialog" class="btn btn-secondary btn-sm" aria-label="{{ __('common.close') }}"><x-icon name="close" class="size-4" /></button>
                </div>

                <x-flash />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="sr-item" class="form-label">{{ __('stock.fields.item') }}</label>
                        @if ($this->item)
                            <div class="flex items-center gap-2">
                                <input id="sr-item" type="text" value="{{ $this->item->name }} ({{ $this->item->sku }})" class="form-input" disabled data-item-locked="{{ $itemLocked ? 'true' : 'false' }}">
                                @unless ($itemLocked)
                                    <button type="button" wire:click="$set('itemId', null)" class="btn btn-secondary btn-sm">{{ __('stock.register.change_item') }}</button>
                                @endunless
                            </div>
                        @else
                            <input wire:model.live.debounce.300ms="itemSearch" id="sr-item" type="search" class="form-input" placeholder="{{ __('stock.register.item_placeholder') }}">
                            <ul class="mt-2 max-h-48 divide-y divide-line overflow-y-auto rounded-md border border-line">
                                @forelse ($this->itemOptions as $option)
                                    <li wire:key="sri-{{ $option->id }}"><button type="button" wire:click="selectItem({{ $option->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-brand-50">{{ $option->name }} <span class="font-mono text-xs text-muted">{{ $option->sku }}</span></button></li>
                                @empty
                                    <li class="px-3 py-2 text-sm text-muted">{{ __('stock.register.no_serialized_items') }}</li>
                                @endforelse
                            </ul>
                        @endif
                        @error('itemId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="sr-wh" class="form-label">{{ __('stock.fields.warehouse') }}</label>
                        <select wire:model="warehouseId" id="sr-wh" class="form-input">
                            @foreach ($warehouses as $warehouse) <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option> @endforeach
                        </select>
                        @error('warehouseId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($this->item)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="sr-entry" class="form-label">{{ __('stock.register.entry') }}</label>
                            <input wire:model="entry" wire:keydown.enter.prevent="addSerial" id="sr-entry" type="text" autocomplete="off" class="form-input font-mono" placeholder="{{ __('stock.register.entry_placeholder') }}" data-serial-entry>
                            @error('entry') <p class="form-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="sr-file" class="form-label">{{ __('stock.register.file') }}</label>
                            <input wire:model="file" id="sr-file" type="file" accept=".csv,.txt,.xlsx" class="form-input">
                            @error('file') <p class="form-error">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-muted">{{ __('stock.register.file_hint', ['rows' => \App\Support\BusinessSettings::int('serials.import_max_rows'), 'mb' => \App\Support\BusinessSettings::int('serials.import_max_mb')]) }} <a href="{{ route('stock.serial-template') }}" class="link">{{ __('stock.register.template') }}</a></p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3 text-sm" data-counts>
                        <span class="font-semibold text-ink">{{ __('stock.register.count', ['count' => count($serials)]) }}</span>
                        <x-badge tone="silver" icon="check">{{ __('stock.register.valid', ['count' => $counts['valid']]) }}</x-badge>
                        <x-badge tone="outline">{{ __('stock.register.duplicates', ['count' => $counts['duplicate']]) }}</x-badge>
                        <x-badge tone="outline" icon="warning">{{ __('stock.register.invalid', ['count' => $counts['invalid']]) }}</x-badge>
                        @if ($counts['duplicate'] + $counts['invalid'] > 0)
                            <button type="button" wire:click="downloadErrors" class="link" data-action="download-errors">{{ __('stock.register.error_report') }}</button>
                        @endif
                    </div>

                    <div class="max-h-72 overflow-y-auto rounded-md border border-line">
                        <table class="min-w-full divide-y divide-line text-sm" data-register-rows>
                            <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase"><tr><th scope="col" class="px-3 py-2">#</th><th scope="col" class="px-3 py-2">{{ __('stock.register.input') }}</th><th scope="col" class="px-3 py-2">{{ __('stock.register.normalized') }}</th><th scope="col" class="px-3 py-2">{{ __('stock.register.result') }}</th><th></th></tr></thead>
                            <tbody class="divide-y divide-line">
                                @forelse ($rows as $index => $row)
                                    <tr wire:key="srr-{{ $index }}-{{ md5($row['raw']) }}" @class(['bg-brand-50' => $row['result'] !== 'ok'])>
                                        <td class="px-3 py-1 text-muted">{{ $index + 1 }}</td>
                                        <td class="px-3 py-1 font-mono text-ink">{{ $row['raw'] }}</td>
                                        <td class="px-3 py-1 font-mono text-ink">{{ $row['serial'] ?? '—' }}</td>
                                        <td class="px-3 py-1 text-xs text-ink">{{ $row['result'] === 'ok' ? __('stock.register.ok') : $row['message'] }}</td>
                                        <td class="px-3 py-1 text-right"><button type="button" wire:click="removeSerial({{ $index }})" class="btn btn-secondary btn-sm" aria-label="{{ __('stock.register.remove') }}">{{ __('stock.register.remove') }}</button></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="px-3 py-3 text-sm text-muted">{{ __('stock.register.empty') }}</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-muted">{{ __('stock.register.choose_item_first') }}</p>
                @endif

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeDialog" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                    <button type="button" wire:click="save" class="btn btn-primary" @disabled(! $canSave) wire:loading.attr="disabled" data-action="save-serials">{{ __('stock.register.save') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
