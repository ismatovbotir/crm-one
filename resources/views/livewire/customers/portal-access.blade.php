<div class="space-y-6" data-portal-access>
    <x-flash />

    <form wire:submit="configure" class="card flex flex-wrap items-end gap-4 p-5" novalidate>
        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="enabled" type="checkbox" class="rounded border-line"> {{ __('portal.access.enabled') }}</label>
        <div>
            <label for="pa-threshold" class="form-label">{{ __('portal.access.threshold') }} ({{ $this->customer->currency }})</label>
            <input wire:model="threshold" id="pa-threshold" type="text" inputmode="decimal" class="form-input" placeholder="{{ __('portal.access.no_threshold') }}">
            @error('threshold') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
        <p class="w-full text-xs text-muted">{{ __('portal.access.hint', ['url' => route('portal.login')]) }}</p>
    </form>

    @include('partials.portal-members', ['members' => $members, 'canManage' => true, 'deactivateAction' => 'deactivate'])

    @if ($pendingInvites->isNotEmpty())
        <section class="card p-5" aria-labelledby="pa-pending" data-pending-invites>
            <h3 id="pa-pending" class="font-semibold text-ink">{{ __('portal.access.pending_title') }}</h3>
            <p class="text-sm text-muted">{{ __('portal.access.pending_hint') }}</p>
            <ul class="mt-3 divide-y divide-line text-sm">
                @foreach ($pendingInvites as $pending)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="pending-{{ $pending->id }}">
                        <span class="text-ink">{{ $pending->fullName() }} <span class="text-muted">{{ $pending->email }}</span></span>
                        <span class="flex gap-2">
                            <button type="button" wire:click="prepareInvite({{ $pending->id }})" class="btn btn-secondary btn-sm" data-action="prepare-invite">{{ __('portal.access.prepare_invite') }}</button>
                            <button type="button" wire:click="dismissInvite({{ $pending->id }})" class="btn btn-secondary btn-sm">{{ __('portal.access.dismiss_invite') }}</button>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <form wire:submit="grant" class="card grid gap-4 p-5 sm:grid-cols-2" novalidate>
        <h3 class="font-semibold text-ink sm:col-span-2">{{ __('portal.users.add') }}</h3>
        <div class="sm:col-span-2">
            <label for="pa-contact" class="form-label">{{ __('portal.access.contact') }}</label>
            <select wire:model="form.contact_id" wire:change="pickContact" id="pa-contact" class="form-input">
                <option value="">—</option>
                @foreach ($this->customer->contacts as $contact) <option value="{{ $contact->id }}">{{ $contact->fullName() }}@if ($contact->email) · {{ $contact->email }}@endif</option> @endforeach
            </select>
        </div>
        <div><label for="pa-email" class="form-label">{{ __('portal.users.email') }}</label><input wire:model="form.email" id="pa-email" type="email" class="form-input">@error('form.email') <p class="form-error">{{ $message }}</p> @enderror @error('email') <p class="form-error">{{ $message }}</p> @enderror</div>
        <div><label for="pa-name" class="form-label">{{ __('portal.users.name') }}</label><input wire:model="form.name" id="pa-name" type="text" class="form-input">@error('form.name') <p class="form-error">{{ $message }}</p> @enderror</div>
        <fieldset class="sm:col-span-2">
            <legend class="form-label">{{ __('portal.users.roles') }}</legend>
            <div class="flex flex-wrap gap-4">
                @foreach ($roles as $value => $label)
                    <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="form.roles" value="{{ $value }}" type="checkbox" class="rounded border-line"> {{ $label }}</label>
                @endforeach
            </div>
            @error('form.roles') <p class="form-error">{{ $message }}</p> @enderror @error('roles') <p class="form-error">{{ $message }}</p> @enderror
        </fieldset>
        <div><label for="pa-limit" class="form-label">{{ __('portal.users.approval_limit') }}</label><input wire:model="form.approval_limit" id="pa-limit" type="text" inputmode="decimal" class="form-input">@error('form.approval_limit') <p class="form-error">{{ $message }}</p> @enderror</div>
        <div class="flex items-end justify-end"><button type="submit" class="btn btn-primary" data-action="grant-portal">{{ __('portal.users.grant') }}</button></div>
    </form>
</div>
