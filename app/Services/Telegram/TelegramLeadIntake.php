<?php

namespace App\Services\Telegram;

use App\Actions\Leads\TransitionLead;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\Locale;
use App\Enums\TelegramMemberRole;
use App\Models\Lead;
use App\Models\NumberSequence;
use App\Models\TelegramChat;
use App\Models\TelegramContact;
use App\Models\TelegramLink;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Notifications\NewTelegramLead;
use App\Services\Leads\LeadRoundRobin;
use App\Services\Telegram\Exceptions\TelegramException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every message that reaches the bot becomes lead work (Spec 04 section 15.2, Spec 03 §17):
 *  - a sender without a CRM account → lead `telegram` (Telegram name/username, chat id, the text as
 *    the need, language from the Telegram locale, status new, owner by round robin or unassigned);
 *  - a linked portal user → lead `portal_request` attached to their customer and contact, owner =
 *    the customer's account owner;
 *  - a linked staff account → no lead (commands keep working);
 *  - further messages from the same chat append to the open lead as activities; after the lead is
 *    closed or converted, the next message opens a new lead.
 * Attachments are never downloaded: only a note with the kind, file name and size is kept (the safe
 * default). Per-chat rate limit (`leads.telegram_rate_per_hour`), bots ignored, text capped. The
 * owner (or, when unassigned, the sales heads and managers) is notified in-app and on Telegram.
 */
class TelegramLeadIntake
{
    public function __construct(protected TelegramClient $client, protected LeadRoundRobin $roundRobin) {}

    /**
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $message
     * @return 'created'|'appended'|'ignored'|'staff'|'limited'
     */
    public function handle(int $chatId, array $from, array $message): string
    {
        if ((bool) ($from['is_bot'] ?? false)) {
            return 'ignored';
        }

        $link = TelegramLink::query()->with('user')->where('chat_id', $chatId)->first();
        $user = $link?->user;

        if ($user !== null && $user->isInternal()) {
            return 'staff';
        }

        $key = 'tg-lead|'.$chatId;

        if (RateLimiter::tooManyAttempts($key, (int) config('leads.telegram_rate_per_hour', 20))) {
            return 'limited';
        }

        RateLimiter::hit($key, 3600);
        $text = $this->text($message);

        if ($text === '') {
            return 'ignored';
        }

        $membership = $user?->isPortal() && $user->is_active ? $user->activeMembership() : null;
        $locale = $this->locale($from, $user);

        [$lead, $created] = DB::transaction(function () use ($chatId, $from, $text, $membership, $user, $locale): array {
            $open = Lead::query()->where('telegram_chat_id', $chatId)->whereIn('status', LeadStatus::openValues())->lockForUpdate()->latest('id')->first();

            if ($open !== null) {
                $open->logActivity('lead_activity', ['type' => 'note', 'text' => $text, 'channel' => 'telegram']);
                $open->forceFill(['last_activity_at' => now()])->saveQuietly();

                return [$open, false];
            }

            $name = trim(((string) ($from['first_name'] ?? '')).' '.((string) ($from['last_name'] ?? '')));
            $username = isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : null;
            // Spec 04 section 18: a private chat linked to a customer (lead converted) keeps attaching to it.
            $linkedChat = $membership === null ? TelegramChat::query()->with('customer')->where('chat_id', $chatId)->whereNotNull('customer_id')->first() : null;
            $customer = $membership?->customer ?? $linkedChat?->customer;
            $contactId = $membership?->contact_id ?? ($linkedChat !== null ? TelegramContact::query()->where('telegram_user_id', (int) ($from['id'] ?? 0))->whereHas('contact', fn ($q) => $q->where('partner_id', $linkedChat->customer_id))->value('contact_id') : null);
            $owner = $customer !== null ? User::query()->whereKey($customer->owner_id)->where('is_active', true)->first() : $this->roundRobin->next();
            $year = (int) CarbonImmutable::now((string) config('offers.timezone', 'Asia/Tashkent'))->format('Y');
            $prefix = (string) config('leads.number_prefix', 'LD');

            $lead = Lead::query()->create([
                'number' => NumberSequence::format($prefix, $year, NumberSequence::next($prefix, $year)),
                'status' => LeadStatus::New,
                'source' => $membership !== null && $customer !== null ? LeadSource::PortalRequest : LeadSource::Telegram,
                'owner_id' => $owner?->id,
                'customer_id' => $customer?->id,
                'contact_id' => $contactId,
                'company_name' => $customer === null ? mb_substr($name !== '' ? $name : ($username !== null ? '@'.$username : 'Telegram '.$chatId), 0, 255) : null,
                'contact_name' => mb_substr($name !== '' ? $name : ($user?->name ?? ''), 0, 255) ?: null,
                'contact_email' => $user?->email,
                'need' => $text,
                'currency' => $customer?->currency ?? (string) config('crm.base_currency', 'UZS'),
                'telegram_chat_id' => $chatId,
                'telegram_user_id' => isset($from['id']) ? (int) $from['id'] : null,
                'telegram_username' => $username,
                'locale' => $locale->value,
                'last_activity_at' => now(),
            ]);

            TransitionLead::recordCreation($lead, null);

            return [$lead, true];
        });

        $this->acknowledge($chatId, $locale, $created);

        if ($created) {
            $this->notify($lead);
        }

        return $created ? 'created' : 'appended';
    }

    /**
     * A message in a group or supergroup (Spec 04 section 17): ONE open lead per group chat, named
     * after the group; every message appends to it with the sender named (identified by Telegram
     * user id). Service messages update members only; commands, bots and edits never open a lead.
     * Rate limited per chat and per sender.
     *
     * @param  array<string, mixed>  $message
     * @return 'created'|'appended'|'ignored'|'limited'|'members'|'migrated'
     */
    public function handleGroup(array $message, bool $edited = false): string
    {
        $chats = app(TelegramChats::class);
        $chat = $chats->observeChat((array) ($message['chat'] ?? []), true, (array) ($message['from'] ?? []));

        if ($chat === null) {
            return 'ignored';
        }

        if (isset($message['migrate_to_chat_id']) || isset($message['migrate_from_chat_id'])) {
            isset($message['migrate_to_chat_id'])
                ? $chats->migrate($chat->chat_id, (int) $message['migrate_to_chat_id'])
                : $chats->migrate((int) $message['migrate_from_chat_id'], $chat->chat_id);

            return 'migrated';
        }

        $target = $chat->merged_into_id !== null ? ($chat->mergedInto ?? $chat) : $chat;

        if (isset($message['new_chat_members']) || isset($message['left_chat_member'])) {
            $chats->serviceMessage($target, $message);

            return 'members';
        }

        $from = (array) ($message['from'] ?? []);
        $sender = $chats->observeContact($from);

        if ($sender === null || $sender->is_bot) {
            return 'ignored'; // bots (including anonymous admins posting as the group) are saved but ignored
        }

        $chats->observeMember($target, $sender, TelegramMemberRole::Member, 'message');

        if (! $chats->recordMessage($target, $sender, (int) ($message['message_id'] ?? 0), $edited ? 'edited' : 'message', (int) ($message['edit_date'] ?? 0))) {
            return 'ignored';
        }

        $text = $this->text($message);

        if ($text === '' || str_starts_with(trim((string) ($message['text'] ?? '')), '/')) {
            return 'ignored';
        }

        foreach (['tg-lead|'.$target->chat_id => (int) config('leads.telegram_group_rate_per_hour', 120), 'tg-lead-user|'.$sender->telegram_user_id => (int) config('leads.telegram_rate_per_hour', 20)] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                return 'limited';
            }
        }

        RateLimiter::hit('tg-lead|'.$target->chat_id, 3600);
        RateLimiter::hit('tg-lead-user|'.$sender->telegram_user_id, 3600);
        $locale = $this->locale($from, $sender->user);
        $activity = ['type' => 'note', 'text' => $edited ? __('telegram.lead.edited', [], Locale::default()->value).' '.$text : $text, 'channel' => 'telegram', 'from' => $sender->fullName(), 'telegram_user_id' => $sender->telegram_user_id];

        [$lead, $created] = DB::transaction(function () use ($target, $sender, $text, $locale, $activity, $edited): array {
            $open = Lead::query()->where('telegram_chat_id', $target->chat_id)->whereIn('status', LeadStatus::openValues())->lockForUpdate()->latest('id')->first();

            if ($open !== null) {
                $open->logActivity('lead_activity', $activity);
                $open->forceFill(['last_activity_at' => now()])->saveQuietly();

                return [$open, false];
            }

            if ($edited) {
                return [null, false];
            }

            $customer = $target->customer;
            $owner = $customer !== null ? User::query()->whereKey($customer->owner_id)->where('is_active', true)->first() : $this->roundRobin->next();
            $contactId = $customer !== null && $sender->contact?->partner_id === $customer->id ? $sender->contact_id : null;

            $lead = Lead::query()->create([
                'number' => $this->nextNumber(),
                'status' => LeadStatus::New,
                'source' => LeadSource::Telegram,
                'owner_id' => $owner?->id,
                'customer_id' => $customer?->id,
                'contact_id' => $contactId,
                'company_name' => $customer === null ? mb_substr($target->displayName(), 0, 255) : null,
                'contact_name' => mb_substr($sender->fullName(), 0, 255),
                'need' => $text,
                'currency' => $customer?->currency ?? (string) config('crm.base_currency', 'UZS'),
                'telegram_chat_id' => $target->chat_id,
                'telegram_user_id' => $sender->telegram_user_id,
                'telegram_username' => $sender->username,
                'locale' => $locale->value,
                'last_activity_at' => now(),
            ]);

            TransitionLead::recordCreation($lead, null);
            $target->forceFill(['lead_id' => $lead->id])->save();

            return [$lead, true];
        });

        if ($lead === null) {
            return 'ignored';
        }

        TelegramMessage::query()->where('telegram_chat_id', $target->id)->where('message_id', (int) ($message['message_id'] ?? 0))->update(['lead_id' => $lead->id]);

        if ($created) {
            $this->acknowledge($target->chat_id, $locale, true);
            $this->notify($lead);
        }

        return $created ? 'created' : 'appended';
    }

    /**
     * An edited private message: appended to the open lead of that chat, never opens a lead.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $message
     */
    public function appendEdit(int $chatId, array $from, array $message): void
    {
        $text = $this->text($message);
        $open = $text !== '' ? Lead::query()->where('telegram_chat_id', $chatId)->whereIn('status', LeadStatus::openValues())->latest('id')->first() : null;

        if ($open !== null && ! (bool) ($from['is_bot'] ?? false)) {
            $open->logActivity('lead_activity', ['type' => 'note', 'text' => __('telegram.lead.edited', [], Locale::default()->value).' '.$text, 'channel' => 'telegram']);
            $open->forceFill(['last_activity_at' => now()])->saveQuietly();
        }
    }

    protected function nextNumber(): string
    {
        $year = (int) CarbonImmutable::now((string) config('offers.timezone', 'Asia/Tashkent'))->format('Y');
        $prefix = (string) config('leads.number_prefix', 'LD');

        return NumberSequence::format($prefix, $year, NumberSequence::next($prefix, $year));
    }

    /**
     * Text of the message plus a note for attachments (never downloaded).
     *
     * @param  array<string, mixed>  $message
     */
    protected function text(array $message): string
    {
        $parts = [];
        $body = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        if ($body !== '') {
            $parts[] = $body;
        }

        foreach (['photo', 'document', 'video', 'voice', 'audio', 'sticker', 'location'] as $kind) {
            if (! isset($message[$kind])) {
                continue;
            }

            $file = $kind === 'photo' ? (array) last((array) $message['photo']) : (array) $message[$kind];
            $name = isset($file['file_name']) ? mb_substr((string) $file['file_name'], 0, 120) : null;
            $size = isset($file['file_size']) ? (int) $file['file_size'] : null;
            $parts[] = __('telegram.lead.attachment', [
                'kind' => __('telegram.lead.kinds.'.$kind),
                'name' => $name ?? '—',
                'size' => $size !== null ? number_format($size / 1024, 0, '.', ' ').' KB' : '—',
            ], Locale::default()->value);
        }

        $text = implode("\n", $parts);
        $max = (int) config('leads.activity_max_length', 2000);

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * @param  array<string, mixed>  $from
     */
    protected function locale(array $from, ?User $user = null): Locale
    {
        if ($user?->locale instanceof Locale) {
            return $user->locale;
        }

        $code = mb_strtolower(substr((string) ($from['language_code'] ?? ''), 0, 2));

        return Locale::fromAvailable($code) ?? Locale::default();
    }

    /**
     * New lead: always acknowledge. Appended message: at most once per 10 minutes.
     */
    protected function acknowledge(int $chatId, Locale $locale, bool $created): void
    {
        if (! $created) {
            $key = 'tg-lead-ack|'.$chatId;

            if (RateLimiter::tooManyAttempts($key, 1)) {
                return;
            }

            RateLimiter::hit($key, 600);
        }

        try {
            $this->client->sendMessage($chatId, e(__($created ? 'telegram.lead.ack' : 'telegram.lead.ack_more', ['app' => (string) config('crm.name')], $locale->value)));
        } catch (TelegramException) {
            // The lead is registered either way; delivery problems are logged by the client.
        }
    }

    protected function notify(Lead $lead): void
    {
        $recipients = $lead->owner_id !== null
            ? User::query()->whereKey($lead->owner_id)->get()
            : User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => $user->can('assign', $lead))->values();

        // In-app, e-mail and Telegram per the recipient's notification preferences (roadmap step 4).
        foreach ($recipients as $recipient) {
            $recipient->notify(new NewTelegramLead($lead));
        }
    }
}
