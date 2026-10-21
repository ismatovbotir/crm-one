<div class="space-y-4" data-customer-equipment>
    <x-flash />
    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.item') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.serial') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.fields.warranty_until') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('service.tickets_title') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($assets as $asset)
                    <tr wire:key="ce-{{ $asset->id }}">
                        <td class="px-4 py-3 text-ink">{{ $asset->displayName() }}@if (! $asset->isVerified())<span class="block text-xs text-muted">{{ __('service.unverified') }}</span>@endif</td>
                        <td class="px-4 py-3 font-mono">@if ($canOpenAsset)<a href="{{ route('service.assets.show', $asset) }}" wire:navigate class="link">{{ $asset->serial_raw }}</a>@else{{ $asset->serial_raw }}@endif</td>
                        <td class="px-4 py-3"><x-service.asset-badge :asset="$asset" /></td>
                        <td class="px-4 py-3 text-ink">{{ $asset->warranty_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? __('service.no_warranty') }}</td>
                        <td class="px-4 py-3">@foreach ($asset->tickets as $ticket)<a href="{{ route('service.tickets.show', $ticket) }}" wire:navigate class="link font-mono text-xs">{{ $ticket->number }}</a> @endforeach</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-0"><x-empty-state :title="__('service.portal.empty')" icon="items" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($canRegister)
        <form wire:submit="register" class="card grid gap-3 p-5 sm:grid-cols-3" data-form="staff-register-asset" novalidate>
            <h3 class="font-semibold text-ink sm:col-span-3">{{ __('service.staff.register') }}</h3>
            <div><label for="cer-serial" class="form-label">{{ __('service.fields.serial') }}</label><input wire:model="form.serial" id="cer-serial" type="text" class="form-input font-mono">@error('form.serial') <p class="form-error" role="alert">{{ $message }}</p> @enderror</div>
            <div><label for="cer-model" class="form-label">{{ __('service.fields.model') }}</label><input wire:model="form.model_name" id="cer-model" type="text" class="form-input">@error('form.model_name') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label for="cer-location" class="form-label">{{ __('service.fields.location') }}</label><input wire:model="form.location" id="cer-location" type="text" class="form-input"></div>
            <div class="flex justify-end sm:col-span-3"><button type="submit" class="btn btn-secondary btn-sm">{{ __('service.staff.register') }}</button></div>
        </form>
    @endif
</div>
