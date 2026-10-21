<?php

namespace App\Notifications\Channels;

use App\Models\TelegramLink;
use App\Notifications\CrmNotification;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramClient;

/**
 * Sends a CRM notification to the user's linked Telegram chat (roadmap step 4). Outbound only to
 * api.telegram.org through TelegramClient; a blocked bot marks the link blocked.
 */
class TelegramChannel
{
    public function __construct(protected TelegramClient $client) {}

    public function send(object $notifiable, CrmNotification $notification): void
    {
        $link = TelegramLink::query()->where('user_id', $notifiable->id)->usable()->first();

        if ($link === null || ! $this->client->enabled()) {
            return;
        }

        try {
            $this->client->sendMessage((int) $link->chat_id, $notification->toTelegram($notifiable));
        } catch (TelegramException $exception) {
            if (in_array($exception->reason, ['bot_blocked', 'chat_not_found'], true)) {
                $link->forceFill(['blocked_at' => now()])->save();
            }
        }
    }
}
