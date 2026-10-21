<div class="space-y-6" data-portal-users>
    <x-flash />
    @include('partials.portal-members', ['members' => $members, 'canManage' => true, 'deactivateAction' => 'deactivate'])

    <form wire:submit="grant" class="card grid gap-4 p-5 sm:grid-cols-2" novalidate>
        <h2 class="font-semibold text-ink sm:col-span-2">{{ __('portal.users.add') }}</h2>
        <div><label for="pu-email" class="form-label">{{ __('portal.users.email') }}</label><input wire:model="form.email" id="pu-email" type="email" class="form-input">@error('form.email') <p class="form-error">{{ $message }}</p> @enderror @error('email') <p class="form-error">{{ $message }}</p> @enderror</div>
        <div><label for="pu-name" class="form-label">{{ __('portal.users.name') }}</label><input wire:model="form.name" id="pu-name" type="text" class="form-input">@error('form.name') <p class="form-error">{{ $message }}</p> @enderror</div>
        <fieldset class="sm:col-span-2">
            <legend class="form-label">{{ __('portal.users.roles') }}</legend>
            <div class="flex flex-wrap gap-4">
                @foreach ($roles as $value => $label)
                    <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="form.roles" value="{{ $value }}" type="checkbox" class="rounded border-line"> {{ $label }}</label>
                @endforeach
            </div>
            @error('form.roles') <p class="form-error">{{ $message }}</p> @enderror @error('roles') <p class="form-error">{{ $message }}</p> @enderror
        </fieldset>
        <div><label for="pu-limit" class="form-label">{{ __('portal.users.approval_limit') }}</label><input wire:model="form.approval_limit" id="pu-limit" type="text" inputmode="decimal" class="form-input">@error('form.approval_limit') <p class="form-error">{{ $message }}</p> @enderror</div>
        <div class="flex items-end justify-end"><button type="submit" class="btn btn-primary">{{ __('portal.users.grant') }}</button></div>
    </form>
</div>
