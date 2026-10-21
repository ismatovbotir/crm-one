<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('leads.intro') }}</p>
        @can('create', \App\Models\Lead::class)
            <a href="{{ route('leads.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-icon name="plus" class="size-4" /> {{ __('leads.new') }}
            </a>
        @endcan
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto_auto] lg:items-center" role="search">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('leads.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="status-filter" class="sr-only">{{ __('leads.fields.status') }}</label>
                <select wire:model.live="status" id="status-filter" class="form-input">
                    <option value="open">{{ __('leads.filters.open') }}</option>
                    <option value="all">{{ __('leads.filters.all') }}</option>
                    @foreach ($statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="source-filter" class="sr-only">{{ __('leads.fields.source') }}</label>
                <select wire:model.live="source" id="source-filter" class="form-input">
                    <option value="">{{ __('leads.filters.any_source') }}</option>
                    @foreach ($sources as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="owner-filter" class="sr-only">{{ __('leads.fields.owner') }}</label>
                <select wire:model.live="owner" id="owner-filter" class="form-input">
                    <option value="">{{ __('leads.filters.any_owner') }}</option>
                    <option value="mine">{{ __('leads.filters.mine') }}</option>
                    <option value="none">{{ __('leads.unassigned') }}</option>
                    @foreach ($this->owners as $ownerOption)
                        <option value="{{ $ownerOption->id }}" wire:key="owner-{{ $ownerOption->id }}">{{ $ownerOption->name }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-ink">
                <input wire:model.live="stale" type="checkbox" class="size-4 accent-brand-600">
                {{ __('leads.filters.stale', ['days' => config('leads.stale_days')]) }}
            </label>
            <button type="button" wire:click="resetFilters" class="btn btn-secondary">{{ __('common.reset_filters') }}</button>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,status,source,owner,stale,resetFilters,gotoPage,nextPage,previousPage">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('leads.fields.number') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('leads.fields.company') }}</th>
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('leads.fields.contact') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('leads.fields.source') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('leads.fields.status') }}</th>
                        <th scope="col" class="hidden px-4 py-3 text-right md:table-cell">{{ __('leads.fields.est_value') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('leads.fields.owner') }}</th>
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('leads.fields.last_activity') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->leads as $lead)
                        <tr wire:key="lead-{{ $lead->id }}" class="hover:bg-brand-50/40">
                            <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-medium text-ink hover:text-brand-700 hover:underline">{{ $lead->number }}</a>
                            </td>
                            <td class="max-w-xs px-4 py-3">
                                <span class="block truncate font-medium text-ink">{{ $lead->companyLabel() }}</span>
                                @if ($lead->customer_id === null)
                                    <span class="block text-xs text-muted">{{ __('leads.not_a_customer_yet') }}</span>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 text-ink lg:table-cell">{{ $lead->contactLabel() ?? '—' }}</td>
                            <td class="hidden px-4 py-3 text-ink md:table-cell">{{ $lead->source->label() }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <x-leads.status-badge :status="$lead->status" />
                                @if ($lead->isStale())
                                    <x-badge tone="danger-outline" icon="clock" class="mt-1" data-stale>{{ __('leads.stale') }}</x-badge>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 text-right whitespace-nowrap text-ink md:table-cell">{{ $lead->estimatedValue()?->format() ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap text-ink">{{ $lead->owner?->name ?? __('leads.unassigned') }}</td>
                            <td class="hidden px-4 py-3 whitespace-nowrap text-muted lg:table-cell">{{ ($lead->last_activity_at ?? $lead->created_at)?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-0">
                                <x-empty-state :title="__('leads.empty')" :description="__('leads.empty_hint')" icon="leads">
                                    @can('create', \App\Models\Lead::class)
                                        <a href="{{ route('leads.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="size-4" /> {{ __('leads.new') }}</a>
                                    @endcan
                                </x-empty-state>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->leads->hasPages())
            <div class="border-t border-line p-4">{{ $this->leads->links() }}</div>
        @endif
    </div>
</div>
