<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('stock.receipts.intro') }}</p>
        @can('create', \App\Models\GoodsReceipt::class)
            <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0" data-new-crm-receipt><x-icon name="plus" class="size-4" /> {{ __('stock.receipts.new') }}</button>
        @endcan
    </div>

    <x-flash />

    <p class="rounded-md border border-line bg-surface p-3 text-sm text-muted" data-receipts-from-1c>{{ __('stock.receipts.from_1c_hint') }}</p>

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" novalidate data-new-receipt>
            <div>
                <label for="r-source" class="form-label">{{ __('stock.fields.source') }}</label>
                <select wire:model.live="form.source_type" id="r-source" class="form-input">
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="r-warehouse" class="form-label">{{ __('stock.fields.warehouse') }}</label>
                <select wire:model="form.warehouse_id" id="r-warehouse" class="form-input">
                    <option value="">—</option>
                    @foreach ($this->warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                    @endforeach
                </select>
                @error('form.warehouse_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            @if ($form['source_type'] === 'customer_return')
                <div>
                    <label for="r-dn" class="form-label">{{ __('stock.fields.return_of_delivery') }}</label>
                    <input wire:model="form.return_of_delivery" id="r-dn" type="text" class="form-input font-mono" placeholder="DN-2026-00001">
                    @error('form.return_of_delivery') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endif
            <div class="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('stock.receipts.create') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr_1fr_auto] sm:items-center" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('stock.receipts.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <select wire:model.live="origin" class="form-input" aria-label="{{ __('stock.receipts.origin') }}">
                <option value="">{{ __('stock.receipts.all_origins') }}</option>
                <option value="onec">{{ __('stock.receipts.origins.onec') }}</option>
                <option value="crm">{{ __('stock.receipts.origins.crm') }}</option>
            </select>
            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="review" type="checkbox" class="form-checkbox"> {{ __('stock.receipts.needs_review') }}</label>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.source') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('stock.fields.warehouse') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->receipts as $receipt)
                        <tr wire:key="gr-{{ $receipt->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap"><a href="{{ route('goods-receipts.show', $receipt) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $receipt->number }}</a><span class="block text-xs text-muted">{{ $receipt->received_on->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}@if ($receipt->external_number) · {{ __('stock.receipts.onec_number', ['number' => $receipt->external_number]) }}@endif</span></td>
                            <td class="px-4 py-3 text-ink">{{ $receipt->source_type->label() }}@if ($receipt->counterparty_name) <span class="block text-xs text-muted">{{ $receipt->counterparty_name }}</span>@endif</td>
                            <td class="px-4 py-3"><x-stock.receipt-status :status="$receipt->status" />@if ($receipt->needs_review) <x-badge tone="outline" icon="warning" data-review-flag>{{ __('stock.receipts.review_reasons.'.$receipt->review_reason) }}</x-badge>@endif</td>
                            <td class="px-4 py-3 text-ink">{{ $receipt->warehouse?->name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-0"><x-empty-state :title="__('stock.receipts.empty')" icon="items" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->receipts->hasPages())
            <div class="border-t border-line p-4">{{ $this->receipts->links() }}</div>
        @endif
    </div>
</div>
