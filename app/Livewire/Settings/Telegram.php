<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramClient;
use App\Support\BusinessSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Telegram bot (Spec 03 4.6, admin only): enable/disable, bot token (encrypted,
 * write-only), bot username (from getMe), generated webhook secret, "Set webhook" and status.
 * Network calls to Telegram happen only on the admin's explicit "Test" / "Set webhook".
 */
#[Layout('layouts.app')]
class Telegram extends Component
{
    public bool $enabled = false;

    public string $token = '';

    public string $username = '';

    public string $webhookStatus = '';

    /** Spec 09 section 14: service ticket updates in customers' Telegram groups. */
    public bool $serviceGroupUpdates = true;

    /** Days the Telegram logs are kept (telegram:prune). */
    public string $retentionDays = '365';

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);
        $this->enabled = Setting::get('telegram.enabled') === '1';
        $this->username = (string) Setting::get('telegram.bot_username');
        $this->serviceGroupUpdates = BusinessSettings::bool('service.telegram_group_updates');
        $this->retentionDays = (string) BusinessSettings::int('operations.retention.telegram_days');
    }

    public function save(): void
    {
        $this->authorize('manage', Setting::class);
        $this->validate([
            'token' => ['nullable', 'string', 'max:100', 'regex:/^\d+:[A-Za-z0-9_-]{30,}$/'],
            'username' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]{5,64}$/'],
            'retentionDays' => ['required', 'integer', 'min:30', 'max:3650'],
        ], [], ['token' => __('telegram.settings.token'), 'username' => __('telegram.settings.username'), 'retentionDays' => __('telegram.settings.retention_days')]);

        $values = ['telegram.enabled' => $this->enabled ? '1' : '0', 'service.telegram_group_updates' => $this->serviceGroupUpdates ? '1' : '0', 'telegram.bot_username' => $this->username !== '' ? $this->username : null, 'operations.retention.telegram_days' => (string) (int) $this->retentionDays];

        if ($this->token !== '') {
            $values['telegram.bot_token'] = Crypt::encryptString($this->token);
        }

        if (Setting::get('telegram.webhook_secret') === null) {
            $values['telegram.webhook_secret'] = Str::random(48);
        }

        Setting::putMany(array_filter($values, fn ($value) => $value !== null), auth()->user());
        $this->token = '';
        session()->flash('status', __('telegram.settings.saved'));
    }

    public function regenerateSecret(): void
    {
        $this->authorize('manage', Setting::class);
        Setting::putMany(['telegram.webhook_secret' => Str::random(48)], auth()->user());
        session()->flash('status', __('telegram.settings.secret_regenerated'));
    }

    public function test(TelegramClient $client): void
    {
        $this->authorize('manage', Setting::class);

        try {
            $me = $client->getMe();
            Setting::putMany(['telegram.bot_username' => (string) ($me['username'] ?? '')], auth()->user());
            $this->username = (string) ($me['username'] ?? '');
            session()->flash('status', __('telegram.settings.test_ok', ['username' => $this->username]));
        } catch (TelegramException $exception) {
            session()->flash('error', __('telegram.settings.failed', ['reason' => $exception->reason]));
        }
    }

    public function setWebhook(TelegramClient $client): void
    {
        $this->authorize('manage', Setting::class);

        try {
            $client->setWebhook(route('telegram.webhook'), (string) $client->webhookSecret());
            $info = $client->getWebhookInfo();
            $this->webhookStatus = __('telegram.settings.webhook_status', ['url' => (string) ($info['url'] ?? ''), 'pending' => (int) ($info['pending_update_count'] ?? 0)]);
            session()->flash('status', __('telegram.settings.webhook_set'));
        } catch (TelegramException $exception) {
            session()->flash('error', __('telegram.settings.failed', ['reason' => $exception->reason]));
        }
    }

    public function render(): View
    {
        return view('livewire.settings.telegram', [
            'tokenSet' => filled(Setting::get('telegram.bot_token')),
            'secretSet' => filled(Setting::get('telegram.webhook_secret')),
        ])->title(__('nav.settings_telegram'));
    }
}
