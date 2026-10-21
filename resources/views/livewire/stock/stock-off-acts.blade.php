<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('stock_off.intro') }}</p>
        @can('create', \App\Models\StockOffAct::class)
            <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0" data-new-stock-off><x-icon name="plus" class="size-4" /> {{ __('stock_off.new') }}</button>
        @endcan
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" novalidate data-new-stock-off-form>
            <div>
                <label for="so-warehouse" class="form-label">{{ __('stock.fields.warehouse') }}</label>
                <select wire:model="form.warehouse_id" id="so-warehouse" class="form-input">
                    <option value="">—</option>
                    @foreach ($this->warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('form.warehouse_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="so-reason" class="form-label">{{ __('stock_off.fields.reason') }}</label>
                <select wire:model.live="form.reason" id="so-reason" class="form-input">
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('form.reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="so-note" class="form-label">{{ __('stock_off.fields.note') }} @if ($form['reason'] === 'other')<x-required-mark />@endif</label>
                <input wire:model="form.note" id="so-note" type="text" class="form-input" maxlength="2000">
                @error('form.note') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('stock_off.create') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr_1fr_1fr]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('stock_off.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="reason" class="form-input" aria-label="{{ __('stock_off.fields.reason') }}">
                <option value="">{{ __('stock_off.all_reasons') }}</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="claim" class="form-input" aria-label="{{ __('stock_off.fields.claim_status') }}">
                <option value="">{{ __('stock_off.all_claims') }}</option>
                @foreach (['open', 'settled', 'rejected'] as $value)
                    <option value="{{ $value }}">{{ __('stock_off.claim_status.'.$value) }}</option>
                @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock_off.fields.reason') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.warehouse') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->acts as $act)
                        <tr wire:key="so-{{ $act->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap"><a href="{{ route('stock-off.show', $act) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $act->number }}</a><span class="block text-xs text-muted">{{ $act->created_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}@if ($act->goodsReceipt) · {{ $act->goodsReceipt->number }}@endif</span></td>
                            <td class="px-4 py-3 text-ink">{{ $act->reason->label() }}@if ($act->claim_status !== 'none') <span class="block text-xs text-muted">{{ __('stock_off.claim_status.'.$act->claim_status) }}</span>@endif</td>
                            <td class="px-4 py-3"><x-stock.stock-off-status :status="$act->status" /></td>
                            <td class="px-4 py-3 text-ink">{{ $act->warehouse?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-0"><x-empty-state :title="__('stock_off.empty')" icon="archive" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->acts->hasPages())
            <div class="border-t border-line p-4">{{ $this->acts->links() }}</div>
        @endif
    </div>
</div>
