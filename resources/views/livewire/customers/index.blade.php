<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('customers.intro') }}</p>
        @can('create', \App\Models\Customer::class)
            <a href="{{ route('customers.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-icon name="plus" class="size-4" /> {{ __('customers.new') }}
            </a>
        @endcan
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto_auto] lg:items-center" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('customers.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="owner-filter" class="sr-only">{{ __('customers.fields.owner_id') }}</label>
                <select wire:model.live="owner" id="owner-filter" class="form-input">
                    <option value="">{{ __('customers.any_owner') }}</option>
                    <option value="mine">{{ __('customers.mine') }}</option>
                    @foreach ($this->owners as $ownerOption)
                        <option value="{{ $ownerOption->id }}" wire:key="owner-{{ $ownerOption->id }}">{{ $ownerOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="industry-filter" class="sr-only">{{ __('customers.fields.industry') }}</label>
                <select wire:model.live="industry" id="industry-filter" class="form-input" data-filter="industry">
                    <option value="">{{ __('partners.any_industry') }}</option>
                    @foreach ($industries as $industryOption)
                        <option value="{{ $industryOption->id }}">{{ $industryOption->name() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="sync-filter" class="sr-only">{{ __('integration.sync_status_label') }}</label>
                <select wire:model.live="sync" id="sync-filter" class="form-input">
                    <option value="">{{ __('integration.any_sync_status') }}</option>
                    @foreach ($syncStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-ink">
                <input wire:model.live="archived" type="checkbox" class="size-4 accent-brand-600">
                {{ __('customers.show_archived') }}
            </label>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,owner,sync,archived,resetFilters,gotoPage,nextPage,previousPage">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('customers.fields.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('customers.fields.tax_id') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('customers.fields.city') }}</th>
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('customers.primary_contact') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('customers.fields.phone') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('customers.fields.owner_id') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.sync_status_label') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->customers as $customer)
                        <tr wire:key="customer-{{ $customer->id }}" class="hover:bg-brand-50/40">
                            <td class="max-w-xs px-4 py-3">
                                <a href="{{ route('customers.show', $customer) }}" wire:navigate class="block truncate font-medium text-ink hover:text-brand-700 hover:underline">{{ $customer->name }}</a>
                                @if ($customer->legal_name)
                                    <span class="block truncate text-xs text-muted">{{ $customer->legal_name }}</span>
                                @endif
                                @if ($customer->trashed())
                                    <x-badge tone="outline" icon="archive" class="mt-1">{{ __('common.archived') }}</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-ink">{{ $customer->tax_id ?? '—' }}</td>
                            <td class="hidden px-4 py-3 text-ink md:table-cell">{{ $customer->city ?? '—' }}</td>
                            <td class="hidden px-4 py-3 text-ink lg:table-cell">{{ $customer->primaryContact?->fullName() ?? '—' }}</td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-ink md:table-cell">{{ $customer->phone ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink">{{ $customer->owner?->name }}</td>
                            <td class="px-4 py-3"><x-sync-badge :status="$customer->sync_status" /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-0">
                                <x-empty-state :title="__('customers.empty')" :description="__('customers.empty_hint')" icon="customers">
                                    @can('create', \App\Models\Customer::class)
                                        <a href="{{ route('customers.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> {{ __('customers.new') }}</a>
                                    @endcan
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->customers->hasPages())
            <div class="border-t border-line p-4">{{ $this->customers->links() }}</div>
        @endif
    </div>
</div>
