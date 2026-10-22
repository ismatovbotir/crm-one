<?php

namespace App\Services\Telegram;

use App\Enums\Locale;
use App\Enums\TelegramChatPurpose;
use App\Enums\TelegramMemberRole;
use App\Models\AuthEvent;
use App\Models\TelegramChat;
use App\Models\TelegramContact;
use App\Models\TelegramLink;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Notifications\TelegramLinkChanged;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\Internal\InternalCommands;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Telegram linking for staff and portal users (Spec 03 4.5, BR-A.5): a single-use deep-link token
 * (sha256 at rest, 10 minutes) consumed by `/start <token>`; one chat per user and one user per
 * chat; `/stop`, unlink in the cabinet and admin force-unlink; contact share stores the user's own
 * verified phone; `my_chat_member` kicked marks the link blocked. Any other message (not a command)
 * is lead work (TelegramLeadIntake, Spec 04 section 15.2). Webhook updates are processed
 * once per update_id. Bot replies are rendered in the user's locale and escaped.
 */
class TelegramLinks
{
    public function __construct(protected TelegramClient $client) {}

    /**
     * @return array{token: string, url: string|null}
     */
    public function createToken(User $user): array
    {
        $token = Str::random(32);
        TelegramLinkToken::query()->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        TelegramLinkToken::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);
        $bot = $this->client->botUsername();

        return ['token' => $token, 'url' => $bot !== null ? 'https://t.me/'.$bot.'?start='.$token : null];
    }

    public function unlink(User $user, ?User $by = null): void
    {
        $link = TelegramLink::query()->where('user_id', $user->id)->first();

        if ($link === null) {
            return;
        }

        $link->delete();
        TelegramContact::query()->where('telegram_user_id', $link->telegram_user_id)->where('user_id', $user->id)->update(['user_id' => null]);
        AuthEvent::record($user->isPortal() ? 'portal' : 'web', $by !== null && $by->id !== $user->id ? 'telegram_force_unlinked' : 'telegram_unlinked', $user);

        // Spec 03 section 17.10: audited and the user is told (in-app and e-mail).
        if ($user->isInternal()) {
            $user->logActivity('telegram_unlinked', ['by' => $by?->name ?? $user->name, 'telegram_user_id' => $link->telegram_user_id]);
            $user->notify(new TelegramLinkChanged('unlinked', $link->username ? '@'.$link->username : (string) ($link->first_name ?? $link->telegram_user_id), $by?->name ?? $user->name));
        }
    }

    /**
     * Handle one webhook update (already authenticated by the secret header). Idempotent per update_id.
     *
     * @param  array<string, mixed>  $update
     */
    public function handle(array $update): void
    {
        $updateId = (int) ($update['update_id'] ?? 0);

        if ($updateId <= 0 || DB::table('telegram_updates')->insertOrIgnore(['update_id' => $updateId, 'received_at' => now()]) === 0) {
            return;
        }

        $chats = app(TelegramChats::class);

        if (isset($update['my_chat_member'])) {
            $chats->botStatusChanged((array) $update['my_chat_member']);

            return;
        }

        if (isset($update['chat_member'])) {
            $chats->memberChanged((array) $update['chat_member']);

            return;
        }

        $edited = ! isset($update['message']) && isset($update['edited_message']);
        $message = (array) ($update['message'] ?? $update['edited_message'] ?? []);
        $chatId = (int) data_get($message, 'chat.id');
        $from = (array) ($message['from'] ?? []);
        $type = (string) data_get($message, 'chat.type');

        if ($chatId === 0) {
            return;
        }

        // Spec 04 section 17: groups and supergroups (negative ids) have their own lead rules.
        if (in_array($type, ['group', 'supergroup'], true)) {
            // Spec 03 section 23: a company group never creates leads; only staff commands count.
            if (TelegramChat::query()->where('chat_id', $chatId)->where('purpose', TelegramChatPurpose::InternalNotifications->value)->exists()) {
                if (! $edited) {
                    app(InternalCommands::class)->handle($message);
                }

                return;
            }

            app(TelegramLeadIntake::class)->handleGroup($message, $edited);

            return;
        }

        if ($type !== 'private' || (bool) ($from['is_bot'] ?? false)) {
            return; // channels and bots are ignored
        }

        $chat = $chats->observeChat((array) $message['chat'], true, $from);
        $sender = $chats->observeContact($from);

        if ($chat !== null && $sender !== null) {
            $chats->observeMember($chat, $sender, TelegramMemberRole::Member, 'message');

            if (! $chats->recordMessage($chat, $sender, (int) ($message['message_id'] ?? 0), $edited ? 'edited' : 'message', (int) ($message['edit_date'] ?? 0))) {
                return;
            }
        }

        if ($edited) {
            app(TelegramLeadIntake::class)->appendEdit($chatId, $from, $message);

            return;
        }

        if (isset($message['contact'])) {
            $this->contact($chatId, $from, (array) $message['contact']);

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (str_starts_with($text, '/start')) {
            $token = trim(substr($text, 6));
            $token === '' ? $this->reply($chatId, 'telegram.help', null) : $this->start($chatId, $from, $token);
        } elseif ($text === '/stop') {
            $link = TelegramLink::query()->where('chat_id', $chatId)->first();

            if ($link !== null) {
                $user = $link->user;
                $this->unlink($user);
                $this->reply($chatId, 'telegram.unlinked', $user);
            }
        } elseif (str_starts_with($text, '/')) {
            $this->reply($chatId, 'telegram.help', TelegramLink::query()->where('chat_id', $chatId)->first()?->user);
        } elseif (app(TelegramLeadIntake::class)->handle($chatId, $from, $message) === 'staff') {
            // Spec 04 15.2: staff accounts never become leads; they get the help text as before.
            $this->reply($chatId, 'telegram.help', TelegramLink::query()->where('chat_id', $chatId)->first()?->user);
        }
    }

    /**
     * @param  array<string, mixed>  $from
     */
    protected function start(int $chatId, array $from, string $token): void
    {
        $row = TelegramLinkToken::query()->where('token_hash', hash('sha256', $token))->whereNull('consumed_at')->where('expires_at', '>', now())->first();
        $user = $row !== null ? User::query()->whereKey($row->user_id)->where('is_active', true)->first() : null;

        if ($user === null) {
            $this->reply($chatId, 'telegram.link_expired', null);

            return;
        }

        $existing = TelegramLink::query()->where('chat_id', $chatId)->first();

        if ($existing !== null && $existing->user_id !== $user->id) {
            $this->reply($chatId, 'telegram.already_linked', $user);

            return;
        }

        DB::transaction(function () use ($row, $user, $chatId, $from): void {
            $row->forceFill(['consumed_at' => now()])->save();
            TelegramLink::query()->updateOrCreate(['user_id' => $user->id], [
                'chat_id' => $chatId,
                'telegram_user_id' => (int) ($from['id'] ?? 0),
                'username' => isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : null,
                'first_name' => isset($from['first_name']) ? mb_substr((string) $from['first_name'], 0, 120) : null,
                'linked_at' => now(),
                'blocked_at' => null,
                // The user opened the single-use deep link from their own CRM session: proof of ownership.
                'verified_at' => now(),
                'verification_method' => 'self_link',
            ]);
            AuthEvent::record($user->isPortal() ? 'portal' : 'web', 'telegram_linked', $user);
            TelegramContact::query()->where('telegram_user_id', (int) ($from['id'] ?? 0))->update(['user_id' => $user->id]);
        });

        $keyboard = ['keyboard' => [[['text' => __('telegram.share_phone', [], $this->locale($user)), 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
        $this->reply($chatId, 'telegram.linked', $user, ['name' => $user->name], $keyboard);
    }

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $contact
     */
    protected function contact(int $chatId, array $from, array $contact): void
    {
        $link = TelegramLink::query()->where('chat_id', $chatId)->first();

        // Only the user's own number (contact.user_id == from.id) is stored.
        if ($link === null || (int) ($contact['user_id'] ?? 0) !== (int) ($from['id'] ?? -1)) {
            return;
        }

        $phone = '+'.preg_replace('/\D/', '', (string) ($contact['phone_number'] ?? ''));

        if (strlen($phone) >= 10) {
            $link->forceFill(['phone' => mb_substr($phone, 0, 20)])->save();
            // Shared by the person themself: the only case a phone is kept on the Telegram contact.
            TelegramContact::query()->where('telegram_user_id', (int) ($from['id'] ?? 0))->update(['phone' => mb_substr($phone, 0, 20)]);
            $this->reply($chatId, 'telegram.phone_saved', $link->user);
        }
    }

    /**
     * @param  array<string, string>  $params
     * @param  array<string, mixed>|null  $keyboard
     */
    protected function reply(int $chatId, string $key, ?User $user, array $params = [], ?array $keyboard = null): void
    {
        $locale = $this->locale($user);
        $params = array_map(fn (string $value): string => e($value), $params);

        try {
            $this->client->sendMessage($chatId, __($key, ['app' => e((string) config('crm.name')), ...$params], $locale), $keyboard);
        } catch (TelegramException $exception) {
            if ($exception->reason === 'bot_blocked') {
                TelegramLink::query()->where('chat_id', $chatId)->update(['blocked_at' => now()]);
            }
        }
    }

    protected function locale(?User $user): string
    {
        return ($user?->locale instanceof Locale ? $user->locale : Locale::default())->value;
    }
}
