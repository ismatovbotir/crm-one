<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('stock_adjustments.intro') }}</p>
        @can('create', \App\Models\StockAdjustment::class)
            <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0" data-new-stock-adjustment><x-icon name="plus" class="size-4" /> {{ __('stock_adjustments.new') }}</button>
        @endcan
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4" novalidate data-new-stock-adjustment-form>
            <div>
                <label for="sa-type" class="form-label">{{ __('stock_adjustments.fields.type') }}</label>
                <select wire:model.live="form.type" id="sa-type" class="form-input">
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted">{{ __('stock_adjustments.type_hints.'.$form['type']) }}</p>
                @error('form.type') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            @if ($form['type'] === 'serial_correction')
                <div>
                    <label for="sa-delivery" class="form-label">{{ __('stock_adjustments.fields.delivery') }} <x-required-mark /></label>
                    <input wire:model="form.delivery_number" id="sa-delivery" type="text" class="form-input font-mono" maxlength="20" placeholder="DN-2026-00001">
                    @error('form.delivery_number') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @else
                <div>
                    <label for="sa-warehouse" class="form-label">{{ __('stock.fields.warehouse') }}</label>
                    <select wire:model="form.warehouse_id" id="sa-warehouse" class="form-input">
                        <option value="">—</option>
                        @foreach ($this->warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                    @error('form.warehouse_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div class="lg:col-span-2">
                <label for="sa-reason" class="form-label">{{ __('stock_adjustments.fields.reason') }} <x-required-mark /></label>
                <input wire:model="form.reason" id="sa-reason" type="text" class="form-input" maxlength="500">
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-4">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('stock_adjustments.create') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr_1fr]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('stock_adjustments.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="type" class="form-input" aria-label="{{ __('stock_adjustments.fields.type') }}">
                <option value="">{{ __('stock_adjustments.all_types') }}</option>
                @foreach ($types as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock_adjustments.fields.type') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.warehouse') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->adjustments as $adjustment)
                        <tr wire:key="sa-{{ $adjustment->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap"><a href="{{ route('stock-adjustments.show', $adjustment) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $adjustment->number }}</a><span class="block text-xs text-muted">{{ $adjustment->created_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}@if ($adjustment->delivery) · {{ $adjustment->delivery->number }}@endif</span></td>
                            <td class="px-4 py-3 text-ink">{{ $adjustment->type->label() }}<span class="block text-xs text-muted">{{ $adjustment->lines_count }} {{ __('stock_adjustments.lines_count') }}</span></td>
                            <td class="px-4 py-3"><x-stock.stock-adjustment-status :status="$adjustment->status" /></td>
                            <td class="px-4 py-3 text-ink">{{ $adjustment->warehouse?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-0"><x-empty-state :title="__('stock_adjustments.empty')" icon="archive" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->adjustments->hasPages())
            <div class="border-t border-line p-4">{{ $this->adjustments->links() }}</div>
        @endif
    </div>
</div>
