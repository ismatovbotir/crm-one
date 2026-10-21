<div class="space-y-6" data-service-assets>
    <div class="card flex flex-wrap items-end gap-3 p-4">
        <div class="min-w-48 flex-1">
            <label for="sa-search" class="form-label">{{ __('service.fields.serial') }}</label>
            <input wire:model.live.debounce.300ms="search" id="sa-search" type="search" class="form-input font-mono" autofocus>
        </div>
        <div>
            <label for="sa-filter" class="form-label">{{ __('service.staff.show') }}</label>
            <select wire:model.live="filter" id="sa-filter" class="form-input">
                <option value="">{{ __('service.all') }}</option>
                <option value="unverified">{{ __('service.unverified') }}</option>
                <option value="disputed">{{ __('service.disputed') }}</option>
            </select>
        </div>
    </div>
    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.serial') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.item') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.customer') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.source') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($assets as $asset)
                    <tr wire:key="sa-{{ $asset->id }}">
                        <td class="px-4 py-3"><a href="{{ route('service.assets.show', $asset) }}" wire:navigate class="link font-mono">{{ $asset->serial_raw }}</a></td>
                        <td class="px-4 py-3 text-ink">{{ $asset->displayName() }}</td>
                        <td class="px-4 py-3 text-ink">{{ $asset->organization?->name }}</td>
                        <td class="px-4 py-3"><x-service.asset-badge :asset="$asset" /></td>
                        <td class="px-4 py-3 text-ink">{{ $asset->source->label() }}
                            @if (! $asset->isVerified())<span class="block text-xs text-muted">{{ __('service.unverified') }}</span>@endif
                            @if ($asset->disputed_at)<span class="block text-xs font-semibold text-brand-700">{{ __('service.disputed') }}</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-0"><x-empty-state :title="__('service.staff.no_assets')" icon="items" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($assets->hasPages())<div class="border-t border-line p-4">{{ $assets->links() }}</div>@endif
    </div>
</div>
