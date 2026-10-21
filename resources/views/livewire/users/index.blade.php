<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('users.intro') }}</p>
        @can('create', \App\Models\User::class)
            <a href="{{ route('users.create') }}" wire:navigate class="btn btn-primary shrink-0">
                <x-icon name="plus" class="size-4" /> {{ __('users.new') }}
            </a>
        @endcan
    </div>

    <x-flash />

    <div class="card">
        <div class="grid gap-3 border-b border-line p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label for="search" class="sr-only">{{ __('common.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="search" type="search" placeholder="{{ __('users.search_placeholder') }}" class="form-input">
            </div>
            <div>
                <label for="role-filter" class="sr-only">{{ __('users.role') }}</label>
                <select wire:model.live="role" id="role-filter" class="form-input">
                    <option value="">{{ __('users.all_roles') }}</option>
                    @foreach ($roles as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type-filter" class="sr-only">{{ __('users.type') }}</label>
                <select wire:model.live="type" id="type-filter" class="form-input">
                    <option value="">{{ __('users.all_types') }}</option>
                    @foreach ($types as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="department-filter" class="sr-only">{{ __('users.department') }}</label>
                <select wire:model.live="department" id="department-filter" class="form-input">
                    <option value="">{{ __('users.all_departments') }}</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->id }}" wire:key="dept-filter-{{ $dept->id }}">{{ $dept->displayName() }}</option>
                    @endforeach
                </select>
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

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('common.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.email') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('users.role') }}</th>
                        <th scope="col" class="hidden px-4 py-3 lg:table-cell">{{ __('users.department') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->users as $user)
                        <tr wire:key="user-{{ $user->id }}" @class(['bg-neutral-50' => ! $user->is_active])>
                            <td class="px-4 py-3 font-medium whitespace-nowrap text-ink">
                                {{ $user->name }}
                                @if ($user->is(auth()->user()))
                                    <span class="ml-1 text-xs font-normal text-muted">({{ __('users.you') }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-muted">{{ $user->email }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($user->isInternal())
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-xs font-semibold',
                                        'bg-brand-600 text-white' => $user->isAdmin(),
                                        'bg-neutral-200 text-ink' => ! $user->isAdmin(),
                                    ])>{{ $user->role?->label() }}</span>
                                @else
                                    <x-badge tone="outline">{{ $user->type->label() }}</x-badge>
                                @endif
                            </td>
                            <td class="hidden px-4 py-3 text-ink lg:table-cell">
                                @php($primaryDepartment = $user->primaryDepartment())
                                {{ $primaryDepartment?->displayName() ?? '—' }}
                                @if ($primaryDepartment)
                                    <span class="block text-xs text-muted">{{ $primaryDepartment->membership->role->label() }}@if ($user->activeDepartments()->count() > 1) · {{ __('users.more_departments', ['count' => $user->activeDepartments()->count() - 1]) }}@endif</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if ($user->is_active)
                                    <span class="inline-flex items-center gap-1.5 text-ink"><span class="size-2 rounded-full bg-ink"></span>{{ __('common.active') }}</span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-brand-700"><span class="size-2 rounded-full bg-brand-600"></span>{{ __('common.inactive') }}</span>
                                @endif
                                @if ($user->telegramLink)
                                    <span class="block text-xs text-muted" data-telegram-linked>{{ $user->telegramLink->isPending() ? __('users.telegram_pending') : __('users.telegram_linked') }}</span>
                                @endif
                                <span class="block text-xs text-muted" data-user-locale>{{ ($user->locale ?? \App\Enums\Locale::default())->label() }}</span>
                                @if ($user->two_factor_confirmed_at)
                                    <span class="block text-xs text-muted" data-two-factor-on>{{ __('users.two_factor_on') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="inline-flex gap-2">
                                    @can('update', $user)
                                        <a href="{{ route('users.edit', $user) }}" wire:navigate class="btn btn-secondary px-3 py-1.5">{{ __('common.edit') }}</a>
                                    @endcan
                                    @if ($user->telegramLink)
                                        @can('update', $user)
                                            <button type="button" wire:click="unlinkTelegram({{ $user->id }})" wire:confirm="{{ __('users.telegram_unlink_confirm', ['name' => $user->name]) }}" class="btn btn-secondary px-3 py-1.5">{{ __('users.telegram_unlink') }}</button>
                                        @endcan
                                    @endif
                                    @if ($user->two_factor_confirmed_at)
                                        @can('resetTwoFactor', $user)
                                            <button type="button" wire:click="resetTwoFactor({{ $user->id }})" wire:confirm="{{ __('users.two_factor_reset_confirm', ['name' => $user->name]) }}" class="btn btn-secondary px-3 py-1.5" data-action="reset-2fa">{{ __('users.two_factor_reset') }}</button>
                                        @endcan
                                    @endif
                                    @can('toggleActive', $user)
                                        @if ($user->is_active)
                                            <button type="button" wire:click="toggleActive({{ $user->id }})" wire:confirm="{{ __('users.deactivate_confirm', ['name' => $user->name]) }}" class="btn btn-danger-outline px-3 py-1.5">{{ __('users.deactivate') }}</button>
                                        @else
                                            <button type="button" wire:click="toggleActive({{ $user->id }})" class="btn btn-dark px-3 py-1.5">{{ __('users.activate') }}</button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-muted">{{ __('users.empty') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->users->hasPages())
            <div class="border-t border-line p-4">
                {{ $this->users->links() }}
            </div>
        @endif
    </div>
</div>
