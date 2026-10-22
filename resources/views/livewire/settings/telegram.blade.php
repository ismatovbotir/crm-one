<div class="space-y-6" data-telegram-settings>
    <x-flash />
    <form wire:submit="save" class="card grid gap-4 p-6 sm:grid-cols-2" novalidate>
        <p class="text-sm text-muted sm:col-span-2">{{ __('telegram.settings.intro') }}</p>
        <label class="flex items-center gap-2 text-sm text-ink sm:col-span-2"><input wire:model="enabled" type="checkbox" class="rounded border-line"> {{ __('telegram.settings.enabled') }}</label>
        <div>
            <label for="tg-token" class="form-label">{{ __('telegram.settings.token') }}</label>
            <input wire:model="token" id="tg-token" type="password" autocomplete="off" class="form-input font-mono" placeholder="{{ $tokenSet ? __('telegram.settings.token_set') : __('telegram.settings.token_not_set') }}">
            @error('token') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="tg-username" class="form-label">{{ __('telegram.settings.username') }}</label>
            <input wire:model="username" id="tg-username" type="text" class="form-input font-mono">
            @error('username') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2" data-telegram-admin>
            <label for="tg-admin" class="form-label">{{ __('telegram.settings.admin_chat_id') }}</label>
            <div class="flex flex-wrap gap-2">
                <input wire:model="adminChatId" id="tg-admin" type="text" inputmode="numeric" autocomplete="off" class="form-input max-w-xs font-mono" placeholder="123456789">
                <button type="button" wire:click="testAdmin" class="btn btn-secondary" @disabled(! $tokenSet)>{{ __('telegram.settings.test_admin') }}</button>
            </div>
            <p class="mt-1 text-xs text-muted">{{ __('telegram.settings.admin_chat_help') }}</p>
            @error('adminChatId') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div data-telegram-retention>
            <label for="tg-retention" class="form-label">{{ __('telegram.settings.retention_days') }}</label>
            <input wire:model="retentionDays" id="tg-retention" type="number" min="30" max="3650" class="form-input">
            <p class="mt-1 text-xs text-muted">{{ __('telegram.settings.retention_help') }}</p>
            @error('retentionDays') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-start gap-2 text-sm text-ink sm:col-span-2" data-service-group-updates><input wire:model="serviceGroupUpdates" type="checkbox" class="mt-0.5 rounded border-line"> <span>{{ __('service.telegram.global_setting') }}<span class="block text-xs text-muted">{{ __('service.telegram.global_setting_hint') }}</span></span></label>
        <p class="text-xs text-muted sm:col-span-2">{{ __('telegram.settings.webhook_url', ['url' => route('telegram.webhook')]) }} · {{ $secretSet ? __('telegram.settings.secret_set') : __('telegram.settings.secret_not_set') }}</p>
        <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
            <button type="button" wire:click="regenerateSecret" class="btn btn-secondary">{{ __('telegram.settings.regenerate_secret') }}</button>
            <button type="button" wire:click="test" class="btn btn-secondary" @disabled(! $tokenSet)>{{ __('telegram.settings.test') }}</button>
            <button type="button" wire:click="setWebhook" class="btn btn-secondary" @disabled(! $tokenSet)>{{ __('telegram.settings.set_webhook') }}</button>
            <button type="submit" class="btn btn-primary">{{ __('common.save') }}</button>
        </div>
        @if ($webhookStatus !== '')<p class="text-sm text-ink sm:col-span-2">{{ $webhookStatus }}</p>@endif
    </form>
    @can('manageTelegramNotifications')
        <livewire:settings.telegram-notifications />
    @endcan
    @can('viewAny', \App\Models\User::class)
        <livewire:telegram.unmatched />
    @endcan
</div>
