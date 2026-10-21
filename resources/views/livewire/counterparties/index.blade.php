<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('counterparties.intro') }}</p>
        @can('create', \App\Models\Counterparty::class)
            {{-- Spec 03 section 24: the unified "New partner" form with the supplier role pre-selected. --}}
            <a href="{{ route('partners.create', ['role' => 'supplier']) }}" wire:navigate class="btn btn-primary shrink-0" data-action="new-counterparty"><x-icon name="plus" class="size-4" /> {{ __('counterparties.new') }}</a>
        @endcan
    </div>

    <x-flash />

    @if ($creating)
        <form wire:submit="create" class="card grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" novalidate data-new-counterparty>
            <div class="sm:col-span-2">
                <label for="cp-name" class="form-label">{{ __('counterparties.fields.name') }}</label>
                <input wire:model="form.name" id="cp-name" type="text" class="form-input" required>
                @error('form.name') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="cp-tax" class="form-label">{{ __('counterparties.fields.tax_id') }}</label>
                <input wire:model="form.tax_id" id="cp-tax" type="text" class="form-input">
                @error('form.tax_id') <p class="form-error">{{ $message }}</p> @enderror
                @if ($existingOrganizationId)
                    <button type="button" wire:click="addRoleToExisting" class="btn btn-secondary btn-sm mt-1" data-action="add-supplier-role">{{ __('organizations.add_supplier_role') }}</button>
                @endif
            </div>
            <div>
                <label for="cp-legal" class="form-label">{{ __('counterparties.fields.legal_name') }}</label>
                <input wire:model="form.legal_name" id="cp-legal" type="text" class="form-input">
            </div>
            <div>
                <label for="cp-country" class="form-label">{{ __('counterparties.fields.country') }}</label>
                <input wire:model="form.country" id="cp-country" type="text" maxlength="2" class="form-input uppercase">
                @error('form.country') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="cp-city" class="form-label">{{ __('counterparties.fields.city') }}</label>
                <input wire:model="form.city" id="cp-city" type="text" class="form-input">
            </div>
            <div>
                <label for="cp-email" class="form-label">{{ __('counterparties.fields.email') }}</label>
                <input wire:model="form.email" id="cp-email" type="email" class="form-input">
                @error('form.email') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="cp-phone" class="form-label">{{ __('counterparties.fields.phone') }}</label>
                <input wire:model="form.phone" id="cp-phone" type="text" class="form-input">
            </div>
            <div>
                <label for="cp-locale" class="form-label">{{ __('counterparties.fields.preferred_locale') }}</label>
                <select wire:model="form.preferred_locale" id="cp-locale" class="form-input">
                    <option value="">—</option>
                    @foreach (\App\Enums\Locale::available() as $locale)
                        <option value="{{ $locale->value }}">{{ $locale->label() }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset class="flex flex-wrap items-center gap-4 sm:col-span-2">
                <legend class="sr-only">{{ __('counterparties.fields.roles') }}</legend>
                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="form.is_supplier" type="checkbox" class="rounded border-line"> {{ __('counterparties.roles.supplier') }}</label>
                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="form.is_carrier" type="checkbox" class="rounded border-line"> {{ __('counterparties.roles.carrier') }}</label>
                @error('form.is_supplier') <p class="form-error w-full">{{ $message }}</p> @enderror
            </fieldset>
            <div class="flex items-end justify-end gap-2 sm:col-span-2 lg:col-span-3">
                <button type="button" wire:click="$set('creating', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary">{{ __('counterparties.create') }}</button>
            </div>
        </form>
    @endif

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-[2fr_1fr_1fr]" role="search">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('counterparties.search_placeholder') }}" class="form-input" aria-label="{{ __('common.search') }}">
            <select wire:model.live="type" class="form-input" aria-label="{{ __('counterparties.fields.roles') }}">
                <option value="">{{ __('counterparties.all_roles') }}</option>
                <option value="supplier">{{ __('counterparties.roles.supplier') }}</option>
                <option value="carrier">{{ __('counterparties.roles.carrier') }}</option>
            </select>
            <select wire:model.live="status" class="form-input" aria-label="{{ __('common.status') }}">
                <option value="">{{ __('common.all_statuses') }}</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('counterparties.fields.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('counterparties.fields.roles') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('counterparties.fields.location') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('counterparties.fields.rating') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->counterparties as $counterparty)
                        <tr wire:key="cp-{{ $counterparty->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3"><a href="{{ route('counterparties.show', $counterparty) }}" wire:navigate class="font-medium text-ink hover:text-brand-700 hover:underline">{{ $counterparty->name }}</a>@if ($counterparty->tax_id)<span class="block font-mono text-xs text-muted">{{ $counterparty->tax_id }}</span>@endif</td>
                            <td class="px-4 py-3 text-ink">
                                @if ($counterparty->is_supplier) <x-badge tone="outline">{{ __('counterparties.roles.supplier') }}</x-badge> @endif
                                @if ($counterparty->is_carrier) <x-badge tone="outline">{{ __('counterparties.roles.carrier') }}</x-badge> @endif
                            </td>
                            <td class="px-4 py-3">@php([$tone, $icon] = $counterparty->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $counterparty->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-ink">{{ collect([$counterparty->city, $counterparty->country])->filter()->implode(', ') ?: '—' }}</td>
                            <td class="px-4 py-3 text-right text-ink">{{ $counterparty->rating ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-0"><x-empty-state :title="__('counterparties.empty')" icon="customers" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($this->counterparties->hasPages())
            <div class="border-t border-line p-4">{{ $this->counterparties->links() }}</div>
        @endif
    </div>
</div>
