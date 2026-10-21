<div>
    <a href="{{ route('users.index') }}" wire:navigate class="link text-sm">&larr; {{ __('users.back') }}</a>

    <form wire:submit="save" class="card mt-4" novalidate>
        <div class="space-y-5 p-6">
            <div class="flex items-center gap-2 text-sm text-muted">
                {{ __('users.type') }}:
                <x-badge :tone="$this->isInternal() ? 'silver' : 'outline'">{{ \App\Enums\UserType::from($type)->label() }}</x-badge>
            </div>

            <div>
                <label for="name" class="form-label">{{ __('common.name') }}</label>
                <input wire:model="name" id="name" type="text" autocomplete="off" required class="form-input">
                @error('name') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="form-label">{{ __('common.email') }}</label>
                <input wire:model="email" id="email" type="email" autocomplete="off" required class="form-input">
                @error('email') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="phone" class="form-label">{{ __('users.phone') }}</label>
                    <input wire:model="phone" id="phone" type="tel" autocomplete="off" class="form-input" maxlength="32">
                    @error('phone') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="position" class="form-label">{{ __('users.position') }}</label>
                    <input wire:model="position" id="position" type="text" autocomplete="off" class="form-input" maxlength="120">
                    @error('position') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="locale" class="form-label">{{ __('users.locale') }}</label>
                    <select wire:model="locale" id="locale" class="form-input" aria-describedby="locale-hint">
                        @foreach (\App\Enums\Locale::cases() as $localeOption)
                            <option value="{{ $localeOption->value }}">{{ $localeOption->label() }}</option>
                        @endforeach
                    </select>
                    <p id="locale-hint" class="mt-1 text-xs text-muted">{{ __('users.locale_hint') }}</p>
                    @error('locale') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($this->isInternal())
                <div>
                    <label for="role" class="form-label">{{ __('users.role') }}</label>
                    <select wire:model.live="role" id="role" class="form-input" @disabled(! $this->canChangeRole())>
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if (! $this->canChangeRole())
                        <p class="mt-1 flex items-center gap-1 text-xs text-muted"><x-icon name="lock" class="size-3.5" /> {{ __('users.cannot_change_own_role') }}</p>
                    @elseif ($role === 'staff')
                        <p class="mt-1 text-xs text-muted">{{ __('users.staff_hint') }}</p>
                    @endif
                    @error('role') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="departmentId" class="form-label">{{ __('users.primary_department') }}</label>
                        <select wire:model="departmentId" id="departmentId" class="form-input">
                            <option value="">{{ __('users.no_department') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" wire:key="dept-{{ $department->id }}">{{ $department->displayName() }}</option>
                            @endforeach
                        </select>
                        @error('departmentId') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="departmentRole" class="form-label">{{ __('users.department_role') }}</label>
                        <select wire:model="departmentRole" id="departmentRole" class="form-input">
                            @foreach ($departmentRoles as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('departmentRole') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($otherDepartments->isNotEmpty())
                    <p class="text-xs text-muted">
                        {{ __('users.other_departments') }}:
                        @foreach ($otherDepartments as $other)
                            <span wire:key="other-{{ $other->id }}">{{ $other->displayName() }} ({{ $other->membership->role->label() }})@if (! $loop->last), @endif</span>
                        @endforeach
                    </p>
                @endif

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="password" class="form-label">{{ $this->isEditing() ? __('users.password_optional') : __('common.password') }}</label>
                        <input wire:model="password" id="password" type="password" autocomplete="new-password" class="form-input">
                        @error('password') <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="password_confirmation" class="form-label">{{ __('common.confirm_password') }}</label>
                        <input wire:model="password_confirmation" id="password_confirmation" type="password" autocomplete="new-password" class="form-input">
                    </div>
                </div>
            @else
                <p class="rounded-md border border-line bg-neutral-50 px-3 py-2 text-sm text-muted">{{ __('users.portal_hint') }}</p>
            @endif
        </div>

        <div class="flex justify-end gap-3 border-t border-line bg-neutral-50 px-6 py-4">
            <a href="{{ route('users.index') }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">
                {{ $this->isEditing() ? __('users.save_changes') : __('users.create') }}
            </button>
        </div>
    </form>

    @if ($this->isEditing() && $this->isInternal())
        <livewire:telegram.unmatched :user-id="$userId" :key="'tg-link-'.$userId" />
    @endif
</div>
