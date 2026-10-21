<div class="space-y-3 rounded-md border border-line p-3" data-serial-entry="{{ $lineType }}-{{ $lineId }}">
    @php($rows = $this->rows)
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold text-ink" data-serial-counter>{{ __('stock.entry.counter', ['count' => $rows->count(), 'expected' => $expected]) }}</p>
        @if ($rows->isNotEmpty())
            <label class="flex items-center gap-1 text-xs text-muted"><input type="checkbox" wire:model.live="errorsOnly" class="size-4 accent-brand-600"> {{ __('stock.entry.errors_only') }}</label>
        @endif
    </div>

    @if ($editable)
        <form wire:submit="addOne" class="flex gap-2" novalidate>
            <label for="sn-{{ $lineId }}" class="sr-only">{{ __('stock.entry.scan') }}</label>
            <input wire:model="serial" id="sn-{{ $lineId }}" type="text" autocomplete="off" spellcheck="false" class="form-input font-mono" placeholder="{{ __('stock.entry.scan') }}" data-serial-input>
            <button type="submit" class="btn btn-secondary shrink-0" wire:loading.attr="disabled">{{ __('stock.entry.add') }}</button>
        </form>

        <details class="text-sm">
            <summary class="cursor-pointer text-muted">{{ __('stock.entry.paste_or_import') }}</summary>
            <div class="mt-2 grid gap-3 lg:grid-cols-2">
                <div>
                    <label for="paste-{{ $lineId }}" class="form-label">{{ __('stock.entry.paste') }}</label>
                    <textarea wire:model="paste" id="paste-{{ $lineId }}" rows="4" class="form-input font-mono text-xs" placeholder="{{ __('stock.entry.paste_hint') }}"></textarea>
                    <button type="button" wire:click="addPaste" class="btn btn-secondary btn-sm mt-2">{{ __('stock.entry.add_valid') }}</button>
                </div>
                <div>
                    <label for="file-{{ $lineId }}" class="form-label">{{ __('stock.entry.file') }}</label>
                    <input wire:model="file" id="file-{{ $lineId }}" type="file" accept=".csv,.txt,.xlsx" class="form-input">
                    @error('file') <p class="form-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-muted">{{ __('stock.entry.file_help') }} <a href="{{ route('stock.serial-template') }}" class="link">{{ __('stock.entry.template') }}</a></p>
                    <button type="button" wire:click="previewFile" class="btn btn-secondary btn-sm mt-2" wire:loading.attr="disabled" wire:target="file,previewFile">{{ __('stock.entry.preview') }}</button>
                </div>
            </div>
        </details>
    @endif

    @if ($this->batch)
        @php($batch = $this->batch)
        <div class="rounded-md border border-line-strong bg-neutral-50 p-3 text-sm" data-import-preview>
            <p class="font-semibold text-ink">{{ __('stock.entry.preview_summary', ['file' => $batch->file_name, 'ok' => $batch->rows_ok, 'warnings' => $batch->rows_warning, 'errors' => $batch->rows_error]) }}</p>
            @if ($batch->encoding_detected) <p class="text-xs text-muted">{{ __('stock.entry.encoding', ['encoding' => $batch->encoding_detected, 'delimiter' => $batch->delimiter_detected]) }}</p> @endif
            <ul class="mt-2 max-h-48 divide-y divide-line overflow-y-auto text-xs">
                @foreach ($batch->rows()->where('result', '!=', 'ok')->limit(200)->get() as $row)
                    <li class="py-1" wire:key="ir-{{ $row->id }}"><span class="font-mono">{{ $row->row_no }}. {{ $row->serial_raw }}</span> — {{ $row->message }}</li>
                @endforeach
            </ul>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="commitImport('all_or_nothing')" class="btn btn-primary btn-sm" @disabled($batch->rows_error > 0) data-commit-all>{{ __('stock.entry.commit_all') }}</button>
                <button type="button" wire:click="commitImport('valid_only')" class="btn btn-secondary btn-sm" data-commit-valid>{{ __('stock.entry.commit_valid') }}</button>
                <button type="button" wire:click="discardImport" class="btn btn-secondary btn-sm">{{ __('common.cancel') }}</button>
            </div>
        </div>
    @endif

    @if ($results !== [])
        <ul class="space-y-1 text-xs" data-serial-results>
            @foreach ($results as $result)
                @continue($errorsOnly && $result['result'] === 'ok')
                <li wire:key="res-{{ $loop->index }}" @class(['flex gap-2', 'text-ink' => in_array($result['result'], ['ok', 'already_in_document', 'numeric_cell'], true), 'text-brand-700' => ! in_array($result['result'], ['ok', 'already_in_document', 'numeric_cell'], true)])>
                    <x-icon :name="$result['result'] === 'ok' ? 'check' : 'warning'" class="size-3.5 shrink-0" />
                    <span class="font-mono">{{ $result['serial'] ?? $result['raw'] }}</span>
                    @if ($result['message'] !== '') <span>— {{ $result['message'] }}</span> @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($rows->isNotEmpty())
        <ol class="grid gap-1 text-xs sm:grid-cols-2 lg:grid-cols-3" data-serial-grid>
            @foreach ($rows as $row)
                @php($isReceipt = $lineType === \App\Models\GoodsReceiptLine::LINE_TYPE)
                <li @class(['rounded border px-2 py-1', 'border-line' => ! $row->is_damaged, 'border-ink bg-neutral-50' => $row->is_damaged]) wire:key="row-{{ $row->id }}" @if ($row->is_damaged) data-serial-damaged @endif>
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-mono text-ink">{{ $loop->iteration }}. {{ $row->serial_number }}</span>
                        <span class="flex items-center gap-2">
                            @if ($isReceipt)
                                <label class="flex items-center gap-1 text-muted">
                                    <input type="checkbox" class="size-3.5 accent-brand-600" @checked($row->is_damaged) @disabled(! $editable || $row->status !== \App\Models\DocumentLineSerial::DRAFT) wire:click="toggleDamaged({{ $row->id }})" data-toggle-damaged="{{ $row->id }}">
                                    {{ __('stock.entry.damaged') }}
                                </label>
                            @endif
                            @if ($editable && $row->status === \App\Models\DocumentLineSerial::DRAFT)
                                <button type="button" wire:click="removeRow({{ $row->id }})" class="text-muted hover:text-brand-700" aria-label="{{ __('stock.entry.remove', ['serial' => $row->serial_number]) }}"><x-icon name="close" class="size-3.5" /></button>
                            @endif
                        </span>
                    </div>
                    @if ($isReceipt && $row->is_damaged)
                        @if ($editable && $row->status === \App\Models\DocumentLineSerial::DRAFT)
                            <div class="mt-1 flex gap-1">
                                <label for="dn-{{ $row->id }}" class="sr-only">{{ __('stock.entry.damage_note') }}</label>
                                <input wire:model="damageNotes.{{ $row->id }}" id="dn-{{ $row->id }}" type="text" maxlength="250" class="form-input py-0.5 text-xs" placeholder="{{ $row->damage_note ?: __('stock.entry.damage_note') }}">
                                <button type="button" wire:click="saveDamageNote({{ $row->id }})" class="btn btn-secondary btn-sm">{{ __('common.save') }}</button>
                            </div>
                        @elseif ($row->damage_note)
                            <p class="mt-1 text-muted">{{ $row->damage_note }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
