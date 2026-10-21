<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('offers.intro') }}</p>
        @can('create', \App\Models\Offer::class)
            <a href="{{ route('offers.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-icon name="plus" class="size-4" /> {{ __('offers.new') }}
            </a>
        @endcan
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto_auto_auto] lg:items-end" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('offers.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="status-filter" class="sr-only">{{ __('common.status') }}</label>
                <select wire:model.live="status" id="status-filter" class="form-input">
                    <option value="">{{ __('common.all_statuses') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="owner-filter" class="sr-only">{{ __('offers.fields.owner') }}</label>
                <select wire:model.live="owner" id="owner-filter" class="form-input">
                    <option value="">{{ __('offers.any_owner') }}</option>
                    <option value="mine">{{ __('offers.mine') }}</option>
                    @foreach ($this->owners as $ownerOption)
                        <option value="{{ $ownerOption->id }}" wire:key="owner-{{ $ownerOption->id }}">{{ $ownerOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="customer-filter" class="sr-only">{{ __('offers.fields.customer') }}</label>
                <select wire:model.live="customer" id="customer-filter" class="form-input">
                    <option value="">{{ __('offers.any_customer') }}</option>
                    @foreach ($this->customers as $customerOption)
                        <option value="{{ $customerOption->id }}" wire:key="customer-{{ $customerOption->id }}">{{ $customerOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="from-filter" class="text-xs text-muted">{{ __('offers.created_from') }}</label>
                <input wire:model.live="from" id="from-filter" type="date" class="form-input">
            </div>
            <div>
                <label for="to-filter" class="text-xs text-muted">{{ __('offers.created_to') }}</label>
                <input wire:model.live="to" id="to-filter" type="date" class="form-input">
            </div>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,status,owner,customer,from,to,resetFilters,gotoPage,nextPage,previousPage">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('offers.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('offers.fields.customer') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('offers.fields.total') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('offers.fields.owner') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('offers.fields.valid_until') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->offers as $offer)
                        @php($daysLeft = (int) now(config('offers.timezone'))->startOfDay()->diffInDays($offer->valid_until, false))
                        <tr wire:key="offer-{{ $offer->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <a href="{{ route('offers.show', $offer) }}" wire:navigate class="font-mono font-medium text-ink hover:text-brand-700 hover:underline">{{ $offer->displayNumber() }}</a>
                                @if ($offer->groups_count > 0)
                                    <span class="block text-xs text-muted">{{ __('offers.has_options') }}</span>
                                @endif
                            </td>
                            <td class="max-w-xs px-4 py-3"><span class="block truncate text-ink">{{ $offer->customer?->name }}</span></td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <x-offers.status-badge :status="$offer->status" />
                                    @if ($offer->status->isOpen() && $offer->selection_complete && $offer->groups_count > 0)
                                        <x-badge tone="dark" icon="check">{{ __('offers.ready_to_accept') }}</x-badge>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right text-ink"><x-offers.total :offer="$offer" /></td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-ink md:table-cell">{{ $offer->owner?->name }}</td>
                            <td class="hidden px-4 py-3 whitespace-nowrap md:table-cell">
                                <span @class(['text-ink', 'font-semibold text-brand-700' => $offer->status->isOpen() && $daysLeft <= 3])>{{ $offer->valid_until->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</span>
                                @if ($offer->status->isOpen() && $daysLeft >= 0 && $daysLeft <= 3)
                                    <span class="block text-xs text-brand-700">{{ trans_choice('offers.expires_in', $daysLeft, ['count' => $daysLeft]) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0">
                                <x-empty-state :title="__('offers.empty')" :description="__('offers.empty_hint')" icon="offers">
                                    @can('create', \App\Models\Offer::class)
                                        <a href="{{ route('offers.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> {{ __('offers.new') }}</a>
                                    @endcan
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->offers->hasPages())
            <div class="border-t border-line p-4">{{ $this->offers->links() }}</div>
        @endif
    </div>
</div>
