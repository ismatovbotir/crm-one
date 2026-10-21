<div class="space-y-6" data-partners-index>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('partners.intro') }}</p>
        @if ($canCreate)
            <a href="{{ route('partners.create') }}" wire:navigate class="btn btn-primary shrink-0" data-action="new-partner">
                <x-icon name="plus" class="size-4" /> {{ __('partners.new') }}
            </a>
        @endif
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_1fr] lg:items-center" role="search">
            <div>
                <label for="partner-search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="partner-search" type="search" placeholder="{{ __('partners.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="partner-role" class="sr-only">{{ __('partners.fields.role') }}</label>
                <select wire:model.live="role" id="partner-role" class="form-input">
                    <option value="">{{ __('partners.any_role') }}</option>
                    @foreach ($this->visibleRoles as $roleOption)
                        <option value="{{ $roleOption }}">{{ __('partners.roles.'.$roleOption) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="partner-status" class="sr-only">{{ __('common.status') }}</label>
                <select wire:model.live="status" id="partner-status" class="form-input">
                    <option value="">{{ __('common.active') }}</option>
                    <option value="archived">{{ __('common.archived') }}</option>
                    <option value="all">{{ __('common.all') }}</option>
                </select>
            </div>
            <div>
                <label for="partner-owner" class="sr-only">{{ __('customers.fields.owner_id') }}</label>
                <select wire:model.live="owner" id="partner-owner" class="form-input">
                    <option value="">{{ __('customers.any_owner') }}</option>
                    <option value="mine">{{ __('customers.mine') }}</option>
                </select>
            </div>
            <div>
                <label for="partner-industry" class="sr-only">{{ __('customers.fields.industry') }}</label>
                <select wire:model.live="industry" id="partner-industry" class="form-input" data-filter="industry">
                    <option value="">{{ __('partners.any_industry') }}</option>
                    @foreach ($industries as $industryOption)
                        <option value="{{ $industryOption->id }}">{{ $industryOption->name() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="partner-sync" class="sr-only">{{ __('integration.sync_status_label') }}</label>
                <select wire:model.live="sync" id="partner-sync" class="form-input">
                    <option value="">{{ __('integration.any_sync_status') }}</option>
                    @foreach ($syncStatuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('customers.fields.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('customers.fields.tax_id') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('partners.fields.roles') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('customers.fields.city') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('customers.fields.owner_id') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.sync_status_label') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->partners as $partner)
                        @php($url = \App\Livewire\Partners\Index::cardUrl($partner, auth()->user()))
                        <tr wire:key="partner-{{ $partner->id }}" class="hover:bg-brand-50/40" data-partner-row="{{ $partner->id }}">
                            <td class="px-4 py-3 font-medium text-ink">
                                @if ($url)<a href="{{ $url }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $partner->name }}</a>@else{{ $partner->name }}@endif
                                @if ($partner->trashed()) <x-badge tone="outline">{{ __('common.archived') }}</x-badge> @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-muted">{{ $partner->tax_id ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="flex flex-wrap gap-1">
                                    @foreach ($partner->roles() as $partnerRole)
                                        <x-badge tone="outline" data-role="{{ $partnerRole }}">{{ __('partners.roles.'.$partnerRole) }}</x-badge>
                                    @endforeach
                                </span>
                            </td>
                            <td class="hidden px-4 py-3 text-muted md:table-cell">{{ $partner->city ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted">{{ $partner->owner?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted">{{ $partner->is_customer ? $partner->sync_status->label() : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-0"><x-empty-state :title="__('partners.empty')" icon="customers" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-line p-4">{{ $this->partners->links() }}</div>
    </div>
</div>
