<?php

namespace App\Services\Telegram;

use App\Authorization\PermissionMap;
use App\Enums\Locale;
use App\Enums\TelegramChatPurpose;
use App\Models\AuthEvent;
use App\Models\TelegramContact;
use App\Models\TelegramLink;
use App\Models\User;
use App\Notifications\TelegramLinkChanged;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\Exceptions\TelegramMatchException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin matching of Telegram users seen by the bot to staff accounts (Spec 03 section 17.10).
 *  - The list shows contacts not linked to anybody: no CRM user, no link (staff or portal), no bots;
 *    members seen only in customer groups are hidden unless asked for.
 *  - "Link" creates a PENDING link: nothing is sent, no notifications or commands for it.
 *  - The user confirms in My cabinet with a 6-digit code the bot sends to that exact chat (proof of
 *    ownership), or an administrator with `users.telegram.force_link` records "verified in person"
 *    with a reason.
 *  - One Telegram id per user and one user per Telegram id; portal links are refused.
 * Every step is audited (auth events + activity) and the user is told in-app and by e-mail.
 */
class TelegramMatching
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public function __construct(protected TelegramClient $client) {}

    /**
     * @return Builder<TelegramContact>
     */
    public function unmatched(string $search = '', bool $includeCustomerGroups = false): Builder
    {
        $term = trim($search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], ltrim($term, '@')).'%';

        return TelegramContact::query()
            ->whereNull('user_id')
            ->where('is_bot', false)
            ->whereNotIn('telegram_user_id', TelegramLink::query()->select('telegram_user_id'))
            ->when(! $includeCustomerGroups, fn (Builder $query) => $query->where(fn (Builder $where) => $where
                ->whereDoesntHave('memberships')
                ->orWhereHas('memberships.chat', fn (Builder $chat) => $chat->where('purpose', TelegramChatPurpose::InternalNotifications->value)->orWhere('type', 'private'))))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('username', 'like', $like)))
            ->with(['memberships.chat:id,chat_id,title,type,purpose'])
            ->orderByDesc('updated_at');
    }

    public function match(TelegramContact $contact, User $user, User $admin): TelegramLink
    {
        if (! $user->isInternal() || ! $user->is_active) {
            throw new TelegramMatchException('user_invalid');
        }

        return DB::transaction(function () use ($contact, $user, $admin): TelegramLink {
            $fresh = TelegramContact::query()->lockForUpdate()->findOrFail($contact->id);
            $taken = TelegramLink::query()->with('user')->where('telegram_user_id', $fresh->telegram_user_id)->orWhere('chat_id', $fresh->telegram_user_id)->first();

            if ($taken !== null) {
                throw new TelegramMatchException($taken->user?->isPortal() ? 'linked_to_portal' : 'telegram_taken', ['name' => (string) $taken->user?->name]);
            }

            if ($fresh->user_id !== null && $fresh->user_id !== $user->id) {
                throw new TelegramMatchException('telegram_taken', ['name' => (string) User::query()->whereKey($fresh->user_id)->value('name')]);
            }

            if (TelegramLink::query()->where('user_id', $user->id)->exists()) {
                throw new TelegramMatchException('user_has_link');
            }

            // Private chat id = Telegram user id.
            $link = TelegramLink::query()->create([
                'user_id' => $user->id,
                'chat_id' => $fresh->telegram_user_id,
                'telegram_user_id' => $fresh->telegram_user_id,
                'username' => $fresh->username,
                'first_name' => $fresh->first_name,
                'linked_at' => now(),
                'linked_by' => $admin->id,
                'verified_at' => null,
                'verification_method' => null,
            ]);
            $fresh->forceFill(['user_id' => $user->id])->save();
            $this->audit($user, 'telegram_matched_pending', $admin, ['telegram_user_id' => $fresh->telegram_user_id, 'username' => $fresh->username]);
            $user->notify(new TelegramLinkChanged('linked_pending', $this->handle($link), $admin->name));

            return $link;
        });
    }

    /** Sends the 6-digit code through the bot to the linked chat (only on the user's request). */
    public function sendCode(User $user): void
    {
        $link = TelegramLink::query()->where('user_id', $user->id)->first();

        if ($link === null || ! $link->isPending()) {
            throw new TelegramMatchException('nothing_to_confirm');
        }

        $key = 'tg-verify|'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new TelegramMatchException('too_many_codes', ['seconds' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 600);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $link->forceFill(['verify_code_hash' => hash('sha256', $code), 'verify_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES), 'verify_attempts' => 0])->save();
        $locale = ($user->locale ?? Locale::default())->value;

        try {
            $this->client->sendMessage($link->chat_id, e(__('telegram.matching.code_message', ['code' => $code, 'minutes' => self::CODE_TTL_MINUTES], $locale)));
        } catch (TelegramException) {
            throw new TelegramMatchException('code_not_delivered');
        }
    }

    public function confirm(User $user, string $code): TelegramLink
    {
        return DB::transaction(function () use ($user, $code): TelegramLink {
            $link = TelegramLink::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($link === null || ! $link->isPending() || $link->verify_code_hash === null) {
                throw new TelegramMatchException('nothing_to_confirm');
            }

            if ($link->verify_attempts >= self::MAX_ATTEMPTS || $link->verify_code_expires_at === null || $link->verify_code_expires_at->isPast()) {
                throw new TelegramMatchException('code_expired');
            }

            if (! hash_equals((string) $link->verify_code_hash, hash('sha256', trim($code)))) {
                $link->forceFill(['verify_attempts' => $link->verify_attempts + 1])->save();

                throw new TelegramMatchException('code_wrong');
            }

            $link->forceFill(['verified_at' => now(), 'verification_method' => 'bot_code', 'verify_code_hash' => null, 'verify_code_expires_at' => null])->save();
            $this->audit($user, 'telegram_link_confirmed', $user, ['telegram_user_id' => $link->telegram_user_id]);
            $user->notify(new TelegramLinkChanged('confirmed', $this->handle($link), $user->name));

            return $link;
        });
    }

    public function forceVerify(User $user, User $admin, string $reason): TelegramLink
    {
        if (! PermissionMap::allows($admin, 'users.telegram.force_link')) {
            throw new TelegramMatchException('force_denied');
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 10) {
            throw new TelegramMatchException('reason_required');
        }

        $link = TelegramLink::query()->where('user_id', $user->id)->first();

        if ($link === null || ! $link->isPending()) {
            throw new TelegramMatchException('nothing_to_confirm');
        }

        $link->forceFill(['verified_at' => now(), 'verification_method' => 'in_person', 'force_reason' => mb_substr($reason, 0, 500), 'verify_code_hash' => null])->save();
        $this->audit($user, 'telegram_link_verified_in_person', $admin, ['reason' => $reason, 'telegram_user_id' => $link->telegram_user_id]);
        $user->notify(new TelegramLinkChanged('verified_in_person', $this->handle($link), $admin->name));

        return $link;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function audit(User $user, string $event, User $by, array $properties): void
    {
        AuthEvent::record('web', $event, $user);
        $user->logActivity($event, [...$properties, 'by' => $by->name]);
    }

    protected function handle(TelegramLink $link): string
    {
        return $link->username ? '@'.$link->username : (string) ($link->first_name ?? $link->telegram_user_id);
    }
}
