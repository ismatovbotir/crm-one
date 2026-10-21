<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Models\TelegramLink;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Services\Telegram\TelegramClient;

/**
 * Channels for one user and event (roadmap step 4): in-app always; e-mail when chosen (or by
 * default) and the user has an address; Telegram when chosen (or by default), the account is linked
 * and not blocked, and the bot is on.
 */
class NotificationPreferences
{
    public function __construct(protected TelegramClient $telegram) {}

    public function wants(User $user, NotificationEvent $event, string $channel): bool
    {
        $stored = $user->notification_preferences[$event->value][$channel] ?? null;

        return is_bool($stored) ? $stored : $event->defaultFor($channel);
    }

    /**
     * @return list<string>
     */
    public function channels(User $user, NotificationEvent $event): array
    {
        $channels = ['database'];

        if ($this->wants($user, $event, 'mail') && $user->hasDeliverableEmail() && $user->is_active) {
            $channels[] = 'mail';
        }

        if ($this->wants($user, $event, 'telegram') && $this->telegramAvailable($user)) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function telegramAvailable(User $user): bool
    {
        return $this->telegram->enabled() && TelegramLink::query()->where('user_id', $user->id)->usable()->exists();
    }

    /**
     * @param  array<string, array<string, bool>>  $choices
     */
    public function save(User $user, array $choices): void
    {
        $clean = [];

        foreach (NotificationEvent::cases() as $event) {
            foreach (NotificationEvent::CHANNELS as $channel) {
                $clean[$event->value][$channel] = (bool) ($choices[$event->value][$channel] ?? false);
            }
        }

        $user->forceFill(['notification_preferences' => $clean])->save();
    }
}
