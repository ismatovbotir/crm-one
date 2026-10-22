<?php

namespace App\Services\Telegram\Internal;

use App\Authorization\PermissionMap;
use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatPurpose;
use App\Enums\TelegramChatType;
use App\Models\TelegramChat;
use App\Models\TelegramDestination;
use App\Models\User;
use App\Notifications\TelegramBotRemoved;
use App\Services\Telegram\TelegramAdminAlerts;
use Illuminate\Support\Collection;

/**
 * Failure alerts for internal destinations (Spec 03 section 23.4): when the bot was removed, lacks
 * rights or the topic is gone, the destination shows the error and the admins (settings.manage)
 * get the existing "bot removed" notification once per new error.
 */
class InternalAlerts
{
    public function destinationFailed(TelegramDestination $destination, string $reason): void
    {
        $alreadyKnown = $destination->last_error === $reason;
        $destination->forceFill(['last_error' => $reason, 'last_error_at' => now()])->saveQuietly();

        if ($alreadyKnown) {
            return;
        }

        $chat = TelegramChat::query()->firstOrCreate(['chat_id' => $destination->chat_id], [
            'type' => $destination->chat_id < 0 ? TelegramChatType::Supergroup : TelegramChatType::Private,
            'title' => $destination->name,
            'bot_status' => TelegramBotStatus::Member,
            'first_seen_at' => now(),
            'purpose' => TelegramChatPurpose::InternalNotifications,
        ]);

        foreach (self::admins() as $admin) {
            $admin->notify(new TelegramBotRemoved($chat));
        }

        app(TelegramAdminAlerts::class)->report('destination_failed', $destination->name.': '.$reason, 'dest|'.$destination->id.'|'.$reason);
    }

    /**
     * @return Collection<int, User>
     */
    public static function admins(): Collection
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $user): bool => $user->isInternal() && PermissionMap::allows($user, 'settings.manage'))->values();
    }
}
