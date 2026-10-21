<div class="space-y-4" data-item-analogues>
    <p class="px-6 pt-4 text-sm text-muted">{{ __('catalog.analogues.intro') }}</p>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                <tr>
                    <th scope="col" class="px-4 py-2">{{ __('catalog.analogues.item') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('catalog.analogues.relation') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('catalog.analogues.stock') }}</th>
                    <th scope="col" class="px-4 py-2 text-right">{{ __('catalog.items.sale_price') }}</th>
                    <th scope="col" class="px-4 py-2">{{ __('catalog.analogues.note') }}</th>
                    <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('common.actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($this->analogues as $row)
                    @php([$link, $analogue] = [$row['link'], $row['item']])
                    <tr wire:key="an-{{ $link->id }}-{{ $row['reverse'] ? 'r' : 'o' }}" data-analogue="{{ $analogue->sku }}">
                        <td class="px-4 py-2"><a href="{{ route('items.show', $analogue) }}" wire:navigate class="link">{{ $analogue->name }}</a> <span class="font-mono text-xs text-muted">{{ $analogue->sku }}</span></td>
                        <td class="px-4 py-2 text-ink">{{ $link->relation->label() }}@if ($link->is_mutual) · <span class="text-muted">{{ __('catalog.analogues.two_way') }}</span>@endif</td>
                        <td class="px-4 py-2 text-muted">{{ $this->stockBand($analogue)?->label() ?? '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-ink">{{ $analogue->salePrice()?->format() ?? '—' }}</td>
                        <td class="px-4 py-2 text-muted">{{ $link->note ?? '' }}</td>
                        <td class="px-4 py-2 text-right">
                            @can('manageAnalogues')
                                <button type="button" wire:click="remove({{ $link->id }})" wire:confirm="{{ __('catalog.analogues.confirm_remove') }}" class="btn btn-secondary btn-sm" data-action="remove-analogue">{{ __('common.remove') }}</button>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-4 text-sm text-muted">{{ __('catalog.analogues.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @can('manageAnalogues')
        <form wire:submit="add" class="mx-6 grid gap-3 rounded-md border border-line p-4 sm:grid-cols-6" data-analogue-add novalidate>
            <h4 class="font-semibold text-ink sm:col-span-6">{{ __('catalog.analogues.add') }}</h4>
            <div class="sm:col-span-2">
                <label for="an-search" class="form-label">{{ __('catalog.analogues.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="an-search" type="search" class="form-input" placeholder="{{ __('catalog.analogues.search_placeholder') }}">
                <label for="an-item" class="sr-only">{{ __('catalog.analogues.item') }}</label>
                <select wire:model="candidateId" id="an-item" class="form-input mt-2">
                    <option value="">—</option>
                    @foreach ($this->candidates as $candidate)<option value="{{ $candidate->id }}" wire:key="anc-{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->sku }}</option>@endforeach
                </select>
                @error('candidateId') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="an-relation" class="form-label">{{ __('catalog.analogues.relation') }}</label>
                <select wire:model="relation" id="an-relation" class="form-input">
                    @foreach ($relations as $relationOption)<option value="{{ $relationOption->value }}">{{ $relationOption->label() }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="an-note" class="form-label">{{ __('catalog.analogues.note') }}</label>
                <input wire:model="note" id="an-note" type="text" maxlength="255" class="form-input">
            </div>
            <div>
                <label for="an-sort" class="form-label">{{ __('catalog.analogues.sort') }}</label>
                <input wire:model="sortOrder" id="an-sort" type="number" min="0" class="form-input">
            </div>
            <div class="flex flex-col justify-end gap-2">
                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="twoWay" type="checkbox" class="rounded border-line"> {{ __('catalog.analogues.two_way') }}</label>
                <button type="submit" class="btn btn-primary" data-action="add-analogue">{{ __('common.add') }}</button>
            </div>
        </form>
    @endcan

    <section class="border-t border-line px-6 py-4" aria-labelledby="an-used-by" data-used-by>
        <h4 id="an-used-by" class="font-semibold text-ink">{{ __('catalog.analogues.used_by') }}</h4>
        @forelse ($this->usedBy as $link)
            <p class="mt-1 text-sm" wire:key="ub-{{ $link->id }}"><a href="{{ route('items.show', $link->item) }}" wire:navigate class="link">{{ $link->item?->name }}</a> <span class="text-muted">· {{ $link->relation->label() }}</span></p>
        @empty
            <p class="mt-1 text-sm text-muted">{{ __('catalog.analogues.used_by_empty') }}</p>
        @endforelse
    </section>
</div>
