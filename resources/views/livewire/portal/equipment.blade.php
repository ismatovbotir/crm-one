<div class="space-y-6" data-portal-equipment>
    <x-flash />
    <p class="text-sm text-muted">{{ __('service.portal.intro') }}</p>

    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-48 flex-1">
            <label for="eq-search" class="form-label">{{ __('service.portal.search') }}</label>
            <input wire:model.live.debounce.300ms="search" id="eq-search" type="search" class="form-input" placeholder="{{ __('service.portal.search_placeholder') }}">
        </div>
        <div>
            <label for="eq-status" class="form-label">{{ __('common.status') }}</label>
            <select wire:model.live="status" id="eq-status" class="form-input">
                <option value="">{{ __('service.all') }}</option>
                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="eq-location" class="form-label">{{ __('service.fields.location') }}</label>
            <input wire:model.live.debounce.300ms="location" id="eq-location" type="text" class="form-input">
        </div>
        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="expiring" type="checkbox" class="rounded border-line"> {{ __('service.portal.expiring') }}</label>
        <a href="{{ route('portal.equipment.export') }}" class="btn btn-secondary btn-sm" data-action="export-equipment">{{ __('service.portal.export') }}</a>
        @if ($canManage)
            <button type="button" wire:click="$toggle('adding')" class="btn btn-primary btn-sm" data-action="add-equipment" aria-expanded="{{ $adding ? 'true' : 'false' }}">{{ __('service.portal.add') }}</button>
        @endif
    </div>

    @if ($canManage && $adding)
        <form wire:submit="register" class="card grid gap-4 p-5 sm:grid-cols-2" data-form="register-asset" novalidate>
            <h2 class="font-semibold text-ink sm:col-span-2">{{ __('service.portal.add_title') }}</h2>
            <p class="text-xs text-muted sm:col-span-2">{{ __('service.portal.add_hint') }}</p>
            <div>
                <label for="ra-serial" class="form-label">{{ __('service.fields.serial') }}</label>
                <input wire:model="form.serial" id="ra-serial" type="text" class="form-input font-mono" autocomplete="off">
                @error('form.serial') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="ra-item-search" class="form-label">{{ __('service.fields.item') }}</label>
                <input wire:model.live.debounce.300ms="itemSearch" id="ra-item-search" type="search" class="form-input" placeholder="{{ __('service.portal.item_search') }}">
                @if ($this->itemOptions->isNotEmpty())
                    <label for="ra-item" class="sr-only">{{ __('service.fields.item') }}</label>
                    <select wire:model="form.item_id" id="ra-item" class="form-input mt-1">
                        <option value="">—</option>
                        @foreach ($this->itemOptions as $item)<option value="{{ $item->id }}">{{ $item->name }} · {{ $item->sku }}</option>@endforeach
                    </select>
                @endif
            </div>
            <div>
                <label for="ra-model" class="form-label">{{ __('service.fields.model') }}</label>
                <input wire:model="form.model_name" id="ra-model" type="text" class="form-input" placeholder="{{ __('service.portal.model_hint') }}">
                @error('form.model_name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="ra-location" class="form-label">{{ __('service.fields.location') }}</label>
                <input wire:model="form.location" id="ra-location" type="text" class="form-input">
            </div>
            <div>
                <label for="ra-purchased" class="form-label">{{ __('service.fields.purchased_on') }}</label>
                <input wire:model="form.purchased_on" id="ra-purchased" type="date" class="form-input">
                @error('form.purchased_on') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="ra-photo" class="form-label">{{ __('service.fields.photo') }}</label>
                <input wire:model="photo" id="ra-photo" type="file" accept="image/jpeg,image/png,image/webp" class="form-input">
                @error('photo') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="sm:col-span-2">
                <label for="ra-notes" class="form-label">{{ __('service.fields.notes') }}</label>
                <textarea wire:model="form.notes" id="ra-notes" rows="2" class="form-input"></textarea>
            </div>
            <div class="flex justify-end sm:col-span-2"><button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('service.portal.add_submit') }}</button></div>
        </form>
    @endif

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.item') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.serial') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.location') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.warranty_until') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($assets as $asset)
                    <tr wire:key="eq-{{ $asset->id }}" data-asset="{{ $asset->uuid }}">
                        <td class="px-4 py-3"><a href="{{ route('portal.equipment.show', $asset) }}" wire:navigate class="link">{{ $asset->displayName() }}</a>
                            @if (! $asset->isVerified()) <span class="block text-xs text-muted">{{ __('service.unverified') }}</span>@endif</td>
                        <td class="px-4 py-3 font-mono text-ink">{{ $asset->serial_raw }}</td>
                        <td class="px-4 py-3"><x-service.asset-badge :asset="$asset" /></td>
                        <td class="px-4 py-3 text-ink">{{ $asset->location ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink">{{ $asset->warranty_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? __('service.no_warranty') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-0"><x-empty-state :title="__('service.portal.empty')" icon="items" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($assets->hasPages())<div class="border-t border-line p-4">{{ $assets->links() }}</div>@endif
    </div>
</div>
