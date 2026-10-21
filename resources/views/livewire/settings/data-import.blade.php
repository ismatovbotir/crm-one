<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('import.intro') }}</p>

    <x-flash />

    <form wire:submit="preview" class="card grid gap-4 p-6 sm:grid-cols-3" novalidate>
        <div>
            <label for="import-kind" class="form-label">{{ __('import.kind') }}</label>
            <select wire:model.live="kind" id="import-kind" class="form-input" @disabled($previewKey !== null)>
                <option value="customers">{{ __('import.kinds.customers') }}</option>
                <option value="items">{{ __('import.kinds.items') }}</option>
            </select>
        </div>
        <div class="sm:col-span-2">
            <label for="import-file" class="form-label">{{ __('import.file') }}</label>
            <input wire:model="file" id="import-file" type="file" accept=".csv,.txt,.xlsx" class="form-input" @disabled($previewKey !== null)>
            <p class="mt-1 text-xs text-muted">{{ __('import.rules', ['rows' => \App\Services\Import\TabularFileReader::MAX_ROWS]) }}</p>
            @error('file') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="text-xs text-muted sm:col-span-3">
            <p>{{ __('import.columns') }} <span class="font-mono">{{ implode(', ', $columns) }}</span></p>
            @if ($kind === 'items') <p class="mt-1">{{ __('import.items_note') }}</p> @endif
            <a href="{{ route('settings.import.template', $kind) }}" class="link">{{ __('import.template') }}</a>
        </div>
        <div class="flex justify-end sm:col-span-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="file,preview" @disabled($previewKey !== null) data-action="preview-import">{{ __('import.check') }}</button>
        </div>
    </form>

    @if ($previewKey !== null)
        <section class="card" aria-labelledby="import-preview" data-import-preview>
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
                <h2 id="import-preview" class="font-semibold text-ink">{{ __('import.preview', ['total' => $total]) }}</h2>
                <p class="flex gap-3 text-sm">
                    <x-badge tone="dark" icon="check">{{ __('import.status.ok') }}: {{ $counts['ok'] }}</x-badge>
                    <x-badge tone="outline">{{ __('import.status.duplicate') }}: {{ $counts['duplicate'] }}</x-badge>
                    <x-badge tone="danger-outline" icon="warning">{{ __('import.status.error') }}: {{ $counts['error'] }}</x-badge>
                </p>
            </div>
            <div class="max-h-[28rem] overflow-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="sticky top-0 bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                        <tr><th scope="col" class="px-4 py-2">{{ __('import.row') }}</th><th scope="col" class="px-4 py-2">{{ __('import.name') }}</th><th scope="col" class="px-4 py-2">{{ __('common.status') }}</th><th scope="col" class="px-4 py-2">{{ __('import.messages_column') }}</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line bg-surface">
                        @foreach ($results as $row)
                            <tr wire:key="ir-{{ $row['row_no'] }}" data-import-row="{{ $row['status'] }}">
                                <td class="px-4 py-2 text-muted tabular-nums">{{ $row['row_no'] }}</td>
                                <td class="px-4 py-2 text-ink">{{ $row['data']['name'] ?? '' }}</td>
                                <td class="px-4 py-2">{{ __('import.status.'.$row['status']) }}</td>
                                <td class="px-4 py-2 text-xs text-muted">{{ implode(' · ', $row['messages']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end gap-2 border-t border-line p-4">
                <button type="button" wire:click="discard" class="btn btn-secondary">{{ __('import.discard') }}</button>
                <button type="button" wire:click="commit" wire:confirm="{{ __('import.confirm', ['count' => $counts['ok']]) }}" class="btn btn-primary" @disabled($counts['ok'] === 0) data-action="commit-import">{{ __('import.commit', ['count' => $counts['ok']]) }}</button>
            </div>
        </section>
    @endif
</div>
