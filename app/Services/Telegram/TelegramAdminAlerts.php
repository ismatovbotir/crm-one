<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bot-related errors go to the administrator's Telegram id (Settings > Telegram bot). The same
 * event is sent at most once every THROTTLE_MINUTES so an outage cannot flood the chat. Sending is
 * best effort: a failure here is only logged and never raises or triggers another alert. Messages
 * carry the event, a short technical detail and the time, never tokens, codes or message texts.
 */
class TelegramAdminAlerts
{
    public const SETTING_KEY = 'telegram.admin_chat_id';

    public const THROTTLE_MINUTES = 15;

    /** Set while an alert is being sent, so a failing alert cannot report itself. */
    private static bool $sending = false;

    public function __construct(protected TelegramClient $client) {}

    public static function adminChatId(): ?int
    {
        $value = Setting::get(self::SETTING_KEY);

        return $value !== null && preg_match('/^-?\d{4,20}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * Report an error event; true when a message was sent. `$key` separates events of one kind
     * (e.g. per chat) for the throttle.
     */
    public function report(string $event, string $detail = '', string $key = ''): bool
    {
        if (self::$sending || self::adminChatId() === null || ! $this->client->enabled()) {
            return false;
        }

        if (! Cache::add('tg-admin-alert:'.sha1($event.'|'.$key), 1, now()->addMinutes(self::THROTTLE_MINUTES))) {
            return false;
        }

        return $this->send($this->format($event, $detail));
    }

    /**
     * Send the settings-page test message (not throttled); false when no valid id is saved or delivery fails.
     */
    public function sendTest(): bool
    {
        return $this->send(e(__('telegram.alerts.test', ['app' => config('crm.name')])));
    }

    protected function send(string $html): bool
    {
        $chatId = self::adminChatId();

        if ($chatId === null) {
            return false;
        }

        self::$sending = true;

        try {
            $this->client->sendMessage($chatId, $html);

            return true;
        } catch (Throwable $exception) {
            Log::warning('telegram.admin_alert_failed', ['reason' => $exception->getMessage()]);

            return false;
        } finally {
            self::$sending = false;
        }
    }

    protected function format(string $event, string $detail): string
    {
        $label = __('telegram.alerts.events.'.$event);
        $label = $label === 'telegram.alerts.events.'.$event ? $event : $label;
        $lines = [
            '⚠️ <b>'.e(__('telegram.alerts.title', ['app' => config('crm.name')])).'</b>',
            e($label),
        ];

        if ($detail !== '') {
            $lines[] = '<code>'.e(mb_substr($detail, 0, 300)).'</code>';
        }

        $lines[] = e(now()->format('d.m.Y H:i:s'));

        return implode("\n", $lines);
    }
}
