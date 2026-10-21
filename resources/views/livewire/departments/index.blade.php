<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('departments.intro') }}</p>
        @can('create', \App\Models\Department::class)
            <a href="{{ route('departments.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-icon name="plus" class="size-4" /> {{ __('departments.new') }}
            </a>
        @endcan
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('departments.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="status-filter" class="sr-only">{{ __('common.status') }}</label>
                <select wire:model.live="status" id="status-filter" class="form-input">
                    <option value="">{{ __('common.all_statuses') }}</option>
                    <option value="active">{{ __('common.active') }}</option>
                    <option value="inactive">{{ __('common.inactive') }}</option>
                </select>
            </div>
        </div>

        <div class="overflow-x-auto" wire:loading.class="opacity-60" wire:target="search,status">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('common.name') }}</th>
                        <th scope="col" class="hidden px-4 py-3 md:table-cell">{{ __('departments.code') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('departments.head') }}</th>
                        <th scope="col" class="px-4 py-3 text-right">{{ __('departments.members_count') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->departments as $department)
                        <tr wire:key="department-{{ $department->id }}" @class(['bg-neutral-50' => ! $department->is_active])>
                            <td class="px-4 py-3 font-medium text-ink">
                                <a href="{{ route('departments.show', $department) }}" wire:navigate class="hover:text-brand-700 hover:underline">{{ $department->displayName() }}</a>
                            </td>
                            <td class="hidden px-4 py-3 font-mono text-xs text-muted md:table-cell">{{ $department->code }}</td>
                            <td class="px-4 py-3 text-ink">{{ $department->head()?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $department->members_count }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($department->is_active)
                                    <x-badge tone="dark" icon="check">{{ __('common.active') }}</x-badge>
                                @else
                                    <x-badge tone="outline">{{ __('common.inactive') }}</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="{{ route('departments.show', $department) }}" wire:navigate class="btn btn-secondary px-3 py-1.5">{{ __('common.open') }}<span class="sr-only"> {{ $department->displayName() }}</span></a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-0"><x-empty-state :title="__('departments.empty')" icon="departments" /></td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
