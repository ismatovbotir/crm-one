<div class="space-y-6">
    <a href="{{ route('departments.index') }}" wire:navigate class="link text-sm">&larr; {{ __('departments.back') }}</a>

    <x-flash />

    @php($editable = $this->canEdit())

    <form wire:submit="save" class="card" novalidate>
        <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="text-base font-semibold text-ink">{{ __('departments.details') }}</h2>
            @if ($this->isEditing())
                @if ($is_active)
                    <x-badge tone="dark" icon="check">{{ __('common.active') }}</x-badge>
                @else
                    <x-badge tone="outline">{{ __('common.inactive') }}</x-badge>
                @endif
            @endif
        </div>

        <fieldset @disabled(! $editable) class="space-y-5 p-6">
            <div class="grid gap-5 sm:grid-cols-3">
                <div>
                    <label for="code" class="form-label">{{ __('departments.code') }}</label>
                    <input wire:model="code" id="code" type="text" autocomplete="off" class="form-input font-mono" maxlength="40">
                    <p class="mt-1 text-xs text-muted">{{ __('departments.code_hint') }}</p>
                    @error('code') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="parent_id" class="form-label">{{ __('departments.parent') }}</label>
                    <select wire:model="parent_id" id="parent_id" class="form-input">
                        <option value="">{{ __('departments.no_parent') }}</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}" wire:key="parent-{{ $parent->id }}">{{ $parent->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('parent_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="sort_order" class="form-label">{{ __('departments.sort_order') }}</label>
                    <input wire:model="sort_order" id="sort_order" type="number" min="0" class="form-input">
                    @error('sort_order') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid gap-5 sm:grid-cols-3">
                @foreach ($locales as $locale)
                    <div wire:key="name-{{ $locale->value }}">
                        <label for="name-{{ $locale->value }}" class="form-label">{{ __('departments.name_in', ['language' => $locale->label()]) }}</label>
                        <input wire:model="names.{{ $locale->value }}" id="name-{{ $locale->value }}" type="text" lang="{{ $locale->value }}" class="form-input" maxlength="120">
                        @error('names.'.$locale->value) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <p class="text-xs text-muted">{{ __('departments.names_hint') }}</p>

            <label class="flex items-center gap-2 text-sm text-ink">
                <input wire:model="is_active" type="checkbox" class="size-4 accent-brand-600">
                {{ __('departments.is_active') }}
            </label>
        </fieldset>

        @if ($editable)
            <div class="flex justify-end gap-3 border-t border-line bg-neutral-50 px-6 py-4">
                <a href="{{ route('departments.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                    {{ $this->isEditing() ? __('common.save') : __('departments.create') }}
                </button>
            </div>
        @endif
    </form>

    @if ($this->isEditing())
        @php($canManageMembers = auth()->user()->can('manageMembers', $this->department))

        <section class="card" aria-labelledby="members-heading">
            <div class="border-b border-line px-6 py-4">
                <h2 id="members-heading" class="text-base font-semibold text-ink">{{ __('departments.members') }}</h2>
                <p class="mt-1 text-xs text-muted">{{ __('departments.members_hint') }}</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                        <tr>
                            <th scope="col" class="px-4 py-3">{{ __('common.name') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('departments.member_role') }}</th>
                            <th scope="col" class="px-4 py-3">{{ __('departments.primary') }}</th>
                            <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">{{ __('common.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line bg-surface">
                        @forelse ($this->members as $member)
                            <tr wire:key="member-{{ $member->id }}">
                                <td class="px-4 py-3">
                                    <span class="block font-medium text-ink">{{ $member->name }}</span>
                                    <span class="block text-xs text-muted">{{ $member->email }}</span>
                                    @error('members.'.$member->id) <p class="form-error">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-4 py-3">
                                    @if ($canManageMembers)
                                        <label for="member-role-{{ $member->id }}" class="sr-only">{{ __('departments.member_role') }}</label>
                                        <select id="member-role-{{ $member->id }}" class="form-input py-1.5" wire:change="changeMemberRole({{ $member->id }}, $event.target.value)">
                                            @foreach ($roles as $value => $label)
                                                <option value="{{ $value }}" @selected($member->membership->role->value === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <x-badge :tone="$member->membership->role === \App\Enums\DepartmentRole::Head ? 'dark' : 'silver'">{{ $member->membership->role->label() }}</x-badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($member->membership->is_primary)
                                        <x-badge tone="outline" icon="star">{{ __('departments.primary') }}</x-badge>
                                    @elseif ($canManageMembers)
                                        <button type="button" wire:click="makePrimary({{ $member->id }})" class="link text-xs">{{ __('departments.make_primary') }}</button>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($canManageMembers)
                                        <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="{{ __('departments.remove_confirm', ['name' => $member->name]) }}" class="btn btn-danger-outline px-3 py-1.5">{{ __('departments.remove') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-0"><x-empty-state :title="__('departments.no_members')" icon="users" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($canManageMembers)
                <form wire:submit="addMember" class="grid gap-3 border-t border-line bg-neutral-50 p-4 sm:grid-cols-[2fr_1fr_auto_auto] sm:items-end" novalidate>
                    <div>
                        <label for="memberUserId" class="form-label">{{ __('departments.member') }}</label>
                        <select wire:model="memberUserId" id="memberUserId" class="form-input">
                            <option value="">{{ __('departments.choose_user') }}</option>
                            @foreach ($this->candidates as $candidate)
                                <option value="{{ $candidate->id }}" wire:key="candidate-{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                            @endforeach
                        </select>
                        @error('memberUserId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="memberRole" class="form-label">{{ __('departments.member_role') }}</label>
                        <select wire:model="memberRole" id="memberRole" class="form-input">
                            @foreach ($roles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('memberRole') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 pb-2 text-sm text-ink">
                        <input wire:model="memberPrimary" type="checkbox" class="size-4 accent-brand-600">
                        {{ __('departments.primary') }}
                    </label>
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                        <x-icon name="plus" class="size-4" /> {{ __('departments.add_member') }}
                    </button>
                </form>
            @endif
        </section>

        <section class="card" aria-labelledby="activity-heading">
            <div class="border-b border-line px-6 py-4">
                <h2 id="activity-heading" class="text-base font-semibold text-ink">{{ __('activity.title') }}</h2>
            </div>
            <x-activity-log :entries="$activities" />
        </section>
    @endif
</div>
