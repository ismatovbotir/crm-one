<?php

namespace App\Services\Telegram;

use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatType;
use App\Enums\TelegramMemberRole;
use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Partner;
use App\Models\TelegramChat;
use App\Models\TelegramChatMember;
use App\Models\TelegramContact;
use App\Models\TelegramDestination;
use App\Models\TelegramLink;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Notifications\TelegramBotRemoved;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\Internal\InternalAlerts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registry of Telegram chats, people and memberships (Spec 04 section 17). Everything is keyed by
 * Telegram ids (chat id, user id); display names are stored for reading only. Members are recorded
 * only when the bot sees them (a message, a service message, a chat_member update or the
 * administrators list on demand); nobody is guessed. Manager actions (link / unlink a customer,
 * merge duplicate chats, refresh members) are audited on the chat (activity log `telegram_chat`).
 */
class TelegramChats
{
    public function __construct(protected TelegramClient $client) {}

    /**
     * @param  array<string, mixed>  $chat  Telegram Chat object
     */
    public function observeChat(array $chat, bool $message = true): ?TelegramChat
    {
        $chatId = (int) ($chat['id'] ?? 0);

        if ($chatId === 0) {
            return null;
        }

        // Private chat id = the person's user id (positive); group/supergroup/channel ids are negative.
        $type = isset($chat['type']) ? TelegramChatType::fromTelegram((string) $chat['type']) : ($chatId > 0 ? TelegramChatType::Private : TelegramChatType::Group);
        $title = $type === TelegramChatType::Private
            ? trim(((string) ($chat['first_name'] ?? '')).' '.((string) ($chat['last_name'] ?? '')))
            : (string) ($chat['title'] ?? '');

        $model = TelegramChat::query()->firstOrNew(['chat_id' => $chatId]);
        $model->fill([
            'type' => $type,
            'title' => $title !== '' ? mb_substr($title, 0, 255) : $model->title,
            'username' => isset($chat['username']) ? mb_substr((string) $chat['username'], 0, 64) : $model->username,
        ]);

        if (! $model->exists) {
            $model->first_seen_at = now();
            $model->bot_status = TelegramBotStatus::Member;
        }

        if ($message) {
            $model->last_message_at = now();
        }

        $model->save();

        return $model;
    }

    /**
     * The person behind a Telegram User object; bots are never recorded (returns null).
     *
     * @param  array<string, mixed>  $from
     */
    public function observeContact(array $from): ?TelegramContact
    {
        $userId = (int) ($from['id'] ?? 0);

        if ($userId <= 0 || (bool) ($from['is_bot'] ?? false)) {
            return null;
        }

        $contact = TelegramContact::query()->firstOrNew(['telegram_user_id' => $userId]);
        $contact->fill([
            'first_name' => isset($from['first_name']) ? mb_substr((string) $from['first_name'], 0, 120) : $contact->first_name,
            'last_name' => isset($from['last_name']) ? mb_substr((string) $from['last_name'], 0, 120) : $contact->last_name,
            'username' => isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : $contact->username,
            'language_code' => isset($from['language_code']) ? mb_substr((string) $from['language_code'], 0, 10) : $contact->language_code,
            'is_bot' => false,
        ]);

        // A staff or portal user is matched by the Telegram id of their own link, never by name.
        $contact->user_id ??= TelegramLink::query()->where('telegram_user_id', $userId)->value('user_id');
        $contact->save();

        return $contact;
    }

    public function observeMember(TelegramChat $chat, TelegramContact $contact, TelegramMemberRole $role, string $source): TelegramChatMember
    {
        $member = TelegramChatMember::query()->firstOrNew(['telegram_chat_id' => $chat->id, 'telegram_contact_id' => $contact->id]);

        if (! $member->exists) {
            $member->first_seen_at = now();
        }

        // A plain message does not downgrade a known creator/admin; it does bring back someone who left.
        $keep = $source === 'message' && $member->exists && in_array($member->role, [TelegramMemberRole::Creator, TelegramMemberRole::Admin], true);
        $member->fill(['role' => $keep ? $member->role : $role, 'source' => $source, 'last_seen_at' => now()])->save();

        return $member;
    }

    /**
     * Appends to the minimal message log; false when this message (kind, edit) was seen before.
     */
    public function recordMessage(TelegramChat $chat, ?TelegramContact $contact, int $messageId, string $kind, int $editDate = 0, ?int $leadId = null): bool
    {
        return TelegramMessage::query()->insertOrIgnore([
            'telegram_chat_id' => $chat->id,
            'telegram_contact_id' => $contact?->id,
            'message_id' => $messageId,
            'kind' => $kind,
            'edit_date' => $editDate,
            'lead_id' => $leadId,
            'received_at' => now(),
        ]) > 0;
    }

    /**
     * `my_chat_member`: the bot was added, promoted, restricted or removed.
     *
     * @param  array<string, mixed>  $update
     */
    public function botStatusChanged(array $update): ?TelegramChat
    {
        $chat = $this->observeChat((array) ($update['chat'] ?? []), false);

        if ($chat === null) {
            return null;
        }

        $status = TelegramBotStatus::fromTelegram((string) data_get($update, 'new_chat_member.status'));
        $previous = $chat->bot_status;
        $chat->forceFill(['bot_status' => $status])->save();

        if ($chat->type === TelegramChatType::Private) {
            if ($status === TelegramBotStatus::Removed) {
                TelegramLink::query()->where('chat_id', $chat->chat_id)->update(['blocked_at' => now()]);
            }

            return $chat;
        }

        $by = $this->observeContact((array) ($update['from'] ?? []));
        $this->audit($chat, 'telegram_bot_status_changed', ['from' => $previous->value, 'to' => $status->value, 'by' => $by?->fullName()]);

        if ($status === TelegramBotStatus::Removed && $previous !== TelegramBotStatus::Removed) {
            foreach ($this->owners($chat) as $owner) {
                $owner->notify(new TelegramBotRemoved($chat));
            }
        }

        return $chat;
    }

    /**
     * `chat_member` update (needs the bot to be an administrator and `chat_member` in allowed_updates).
     *
     * @param  array<string, mixed>  $update
     */
    public function memberChanged(array $update): void
    {
        $chat = $this->observeChat((array) ($update['chat'] ?? []), false);
        $contact = $this->observeContact((array) data_get($update, 'new_chat_member.user', []));

        if ($chat !== null && $contact !== null && $chat->isGroup()) {
            $this->observeMember($chat, $contact, TelegramMemberRole::fromTelegram((string) data_get($update, 'new_chat_member.status')), 'chat_member');
        }
    }

    /**
     * Service messages: members joined or left.
     *
     * @param  array<string, mixed>  $message
     */
    public function serviceMessage(TelegramChat $chat, array $message): void
    {
        foreach ((array) ($message['new_chat_members'] ?? []) as $user) {
            $contact = $this->observeContact((array) $user);

            if ($contact !== null) {
                $this->observeMember($chat, $contact, TelegramMemberRole::Member, 'message');
            }
        }

        if (isset($message['left_chat_member'])) {
            $contact = $this->observeContact((array) $message['left_chat_member']);

            if ($contact !== null) {
                $this->observeMember($chat, $contact, TelegramMemberRole::Left, 'message');
            }
        }
    }

    /**
     * A group became a supergroup: Telegram gives it a new id. The chat row, its open lead and
     * future messages keep working under the new id.
     */
    public function migrate(int $oldChatId, int $newChatId): void
    {
        DB::transaction(function () use ($oldChatId, $newChatId): void {
            $old = TelegramChat::query()->where('chat_id', $oldChatId)->lockForUpdate()->first();
            $new = TelegramChat::query()->where('chat_id', $newChatId)->lockForUpdate()->first();

            if ($old === null) {
                return;
            }

            if ($new !== null && $new->id !== $old->id) {
                $this->mergeRows($new, $old);
            } else {
                $old->forceFill(['chat_id' => $newChatId, 'type' => TelegramChatType::Supergroup])->save();
            }

            Lead::query()->where('telegram_chat_id', $oldChatId)->update(['telegram_chat_id' => $newChatId]);
            // Spec 03 section 23.4: internal destinations follow the new id.
            TelegramDestination::query()->where('chat_id', $oldChatId)->update(['chat_id' => $newChatId]);
            $this->audit($new ?? $old, 'telegram_chat_migrated', ['from' => $oldChatId, 'to' => $newChatId]);
        });
    }

    /**
     * Links a group to a customer: its open lead gets the customer, later leads start on it.
     */
    public function linkCustomer(TelegramChat $chat, Partner $customer, User $by): void
    {
        DB::transaction(function () use ($chat, $customer): void {
            $previous = $chat->customer_id;
            $chat->forceFill(['customer_id' => $customer->id])->save();
            $lead = $chat->lead_id !== null ? Lead::query()->find($chat->lead_id) : null;

            if ($lead !== null && $lead->customer_id === null && $lead->status->isOpen()) {
                $lead->forceFill(['customer_id' => $customer->id, 'company_name' => null, 'currency' => $lead->currency ?: $customer->currency])->save();
            }

            $this->audit($chat, 'telegram_chat_linked', ['customer' => $customer->name, 'previous_customer_id' => $previous]);
            $customer->logActivity('telegram_chat_linked', ['chat' => $chat->displayName()]);
        });
    }

    public function unlinkCustomer(TelegramChat $chat, User $by): void
    {
        $customer = $chat->customer;
        $chat->forceFill(['customer_id' => null])->save();
        $this->audit($chat, 'telegram_chat_unlinked', ['customer' => $customer?->name]);
        $customer?->logActivity('telegram_chat_unlinked', ['chat' => $chat->displayName()]);
    }

    /**
     * Merges a duplicate chat into another (members, message log, customer, open lead) and marks it
     * as merged. Both must be groups; the duplicate keeps its row for the audit trail.
     */
    public function merge(TelegramChat $duplicate, TelegramChat $into, User $by): void
    {
        if ($duplicate->is($into) || ! $duplicate->isGroup() || ! $into->isGroup() || $into->merged_into_id !== null) {
            throw new TelegramException('merge_invalid');
        }

        if ($duplicate->customer_id !== null && $into->customer_id !== null && $duplicate->customer_id !== $into->customer_id) {
            throw new TelegramException('merge_customer_conflict');
        }

        DB::transaction(fn () => $this->mergeRows($into, $duplicate));
        $this->audit($into, 'telegram_chats_merged', ['merged' => $duplicate->displayName(), 'merged_chat_id' => $duplicate->chat_id]);
    }

    /**
     * On demand only (manager button): administrators and the member count from Telegram.
     *
     * @return array{admins: int, count: int|null}
     */
    public function refreshMembers(TelegramChat $chat, User $by): array
    {
        $admins = $this->client->getChatAdministrators($chat->chat_id);
        $count = $this->client->getChatMemberCount($chat->chat_id);
        $seen = 0;

        foreach ($admins as $admin) {
            $contact = $this->observeContact((array) ($admin['user'] ?? []));

            if ($contact !== null) {
                $this->observeMember($chat, $contact, TelegramMemberRole::fromTelegram((string) ($admin['status'] ?? 'administrator')), 'administrators');
                $seen++;
            }
        }

        $chat->forceFill(['member_count' => $count, 'members_refreshed_at' => now()])->save();
        $this->audit($chat, 'telegram_members_refreshed', ['admins' => $seen, 'count' => $count]);

        return ['admins' => $seen, 'count' => $count];
    }

    /**
     * Customers (other than $except) whose linked groups this person is a member of.
     *
     * @return Collection<int, Customer>
     */
    public function otherCompanies(TelegramContact $contact, ?int $except): Collection
    {
        $customerIds = TelegramChat::query()
            ->whereIn('id', $contact->memberships()->where('role', '!=', TelegramMemberRole::Left->value)->select('telegram_chat_id'))
            ->whereNotNull('customer_id')
            ->when($except !== null, fn ($query) => $query->where('customer_id', '!=', $except))
            ->distinct()->pluck('customer_id');

        $customerIds = $customerIds->merge($contact->contact_id !== null ? Contact::query()->whereKey($contact->contact_id)->pluck('partner_id') : [])
            ->reject(fn ($id): bool => $except !== null && (int) $id === $except)->unique();

        return Customer::query()->whereKey($customerIds->all())->get(['id', 'name']);
    }

    public function audit(TelegramChat $chat, string $event, array $properties = []): void
    {
        ActivityLog::query()->create([
            'subject_type' => $chat->getMorphClass(),
            'subject_id' => $chat->id,
            'causer_id' => auth()->id(),
            'event' => $event,
            'properties' => $properties,
        ]);
    }

    /**
     * Who hears about the group: the open lead's owner, else the linked customer's owner.
     *
     * @return Collection<int, User>
     */
    public function owners(TelegramChat $chat): Collection
    {
        if ($chat->isInternal()) {
            return InternalAlerts::admins();
        }

        $ownerId = $chat->lead?->owner_id ?? $chat->customer?->owner_id;

        if ($ownerId !== null) {
            return User::query()->whereKey($ownerId)->where('is_active', true)->get();
        }

        $lead = $chat->lead;

        return $lead !== null
            ? User::query()->where('is_active', true)->get()->filter(fn (User $user): bool => $user->can('assign', $lead))->values()
            : collect();
    }

    protected function mergeRows(TelegramChat $into, TelegramChat $duplicate): void
    {
        foreach ($duplicate->memberships()->get() as $member) {
            $existing = TelegramChatMember::query()->where('telegram_chat_id', $into->id)->where('telegram_contact_id', $member->telegram_contact_id)->first();

            if ($existing === null) {
                $member->forceFill(['telegram_chat_id' => $into->id])->save();
            } else {
                $existing->forceFill(['first_seen_at' => min($existing->first_seen_at, $member->first_seen_at), 'last_seen_at' => max($existing->last_seen_at, $member->last_seen_at)])->save();
                $member->delete();
            }
        }

        // The message log stays on the duplicate (append-only audit; message ids are per chat).
        $into->forceFill([
            'customer_id' => $into->customer_id ?? $duplicate->customer_id,
            'lead_id' => $into->lead_id ?? $duplicate->lead_id,
            'last_message_at' => max($into->last_message_at, $duplicate->last_message_at),
        ])->save();
        $duplicate->forceFill(['merged_into_id' => $into->id, 'lead_id' => null])->save();
    }
}
