<div class="flex flex-wrap items-center gap-2" data-partner-roles>
    @foreach ($this->partner->roles() as $current)
        <x-badge tone="outline" data-role="{{ $current }}">{{ __('partners.roles.'.$current) }}</x-badge>
    @endforeach
    @can('editRoles', $this->partner)
        @unless ($editing)
            <button type="button" wire:click="edit" class="btn btn-secondary btn-sm" data-action="edit-roles">{{ __('partners.edit_roles') }}</button>
        @endunless
    @endcan
    @if ($editing)
        <form wire:submit="save" class="mt-2 w-full rounded-md border border-line p-3" novalidate>
            <fieldset>
                <legend class="form-label">{{ __('partners.fields.roles') }}</legend>
                <div class="flex flex-wrap gap-4">
                    @foreach (['customer', 'supplier', 'carrier'] as $roleOption)
                        <label class="flex items-center gap-2 text-sm text-ink" wire:key="er-{{ $roleOption }}">
                            <input type="checkbox" wire:model="roles" value="{{ $roleOption }}" class="rounded border-line" @disabled(! in_array($roleOption, $allowed, true))>
                            {{ __('partners.roles.'.$roleOption) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <p class="mt-2 text-xs text-muted">{{ __('partners.roles_edit_hint') }}</p>
            @error('roles') <p class="form-error" role="alert">{{ $message }}</p> @enderror
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" wire:click="$set('editing', false)" class="btn btn-secondary btn-sm">{{ __('common.cancel') }}</button>
                <button type="submit" class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
            </div>
        </form>
    @endif
</div>
