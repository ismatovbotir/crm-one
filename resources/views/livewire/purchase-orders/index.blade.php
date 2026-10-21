<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('purchase_orders.intro') }}</p>
        @can('create', \App\Models\PurchaseOrder::class)
            <button type="button" wire:click="$toggle('creating')" class="btn btn-primary shrink-0" data-action="new-po"><x-icon name="plus" class="size-4" /> {{ __('purchase_orders.new') }}</button>
        @endcan
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-4" novalidate data-new-po>
            <div class="sm:col-span-2">
                <label for="po-supplier" class="form-label">{{ __('purchase_orders.fields.supplier') }}</label>
                <select wire:model="form.supplier_id" id="po-supplier" class="form-input">
                    <option value="">—</option>
                    @foreach ($suppliers as $supplier) <option value="{{ $supplier->id }}">{{ $supplier->name }}</option> @endforeach
                </select>
                @error('form.supplier_id') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="po-currency" class="form-label">{{ __('purchase_orders.fields.currency') }}</label>
                <select wire:model="form.currency" id="po-currency" class="form-input">
                    @foreach (config('tenders.currencies') as $currency) <option value="{{ $currency }}">{{ $currency }}</option> @endforeach
                </select>
            </div>
            <div>
                <label for="po-incoterm" class="form-label">{{ __('purchase_orders.fields.incoterm') }}</label>
                <select wire:model="form.incoterm" id="po-incoterm" class="form-input">
                    <option value="">—</option>
                    @foreach ($incoterms as $value => $label) <option value="{{ $value }}">{{ $value }} — {{ $label }}</option> @endforeach
                </select>
            </div>
            <div class="sm:col-span-3">
                <label for="po-place" class="form-label">{{ __('purchase_orders.fields.incoterm_place') }}</label>
                <input wire:model="form.incoterm_place" id="po-place" type="text" class="form-input">
            </div>
            <div class="flex items-end justify-end gap-2">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('purchase_orders.create') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('purchase_orders.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('purchase_orders.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('purchase_orders.fields.supplier') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('purchase_orders.fields.subtotal') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->orders as $po)
                        <tr wire:key="po-{{ $po->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3"><a href="{{ route('purchase-orders.show', $po) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $po->number }}</a>@if ($po->onec_number)<span class="block text-xs text-muted">1C: {{ $po->onec_number }}</span>@endif</td>
                            <td class="px-4 py-3 text-ink">{{ $po->supplier?->name }}</td>
                            <td class="px-4 py-3">@php([$tone, $icon] = $po->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $po->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap text-ink">{{ $po->money($po->subtotal_minor)->format() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="p-0"><x-empty-state :title="__('purchase_orders.empty')" icon="orders" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->orders->hasPages())
            <div class="border-t border-line p-4">{{ $this->orders->links() }}</div>
        @endif
    </div>
</div>
