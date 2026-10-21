<?php

namespace App\Models;

use App\Enums\TelegramMemberRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person seen in a chat (Spec 04 section 17). Source: `message` (sent a message or a service
 * message named them), `chat_member` (member update) or `administrators` (getChatAdministrators).
 * Members the bot never saw are not guessed.
 *
 * @property TelegramMemberRole $role
 * @property CarbonImmutable $last_seen_at
 */
#[Fillable(['telegram_chat_id', 'telegram_contact_id', 'role', 'source', 'first_seen_at', 'last_seen_at'])]
class TelegramChatMember extends Model
{
    public const SOURCES = ['message', 'chat_member', 'administrators'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => TelegramMemberRole::class, 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<TelegramChat, $this>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramChat::class, 'telegram_chat_id');
    }

    /**
     * @return BelongsTo<TelegramContact, $this>
     */
    public function telegramContact(): BelongsTo
    {
        return $this->belongsTo(TelegramContact::class, 'telegram_contact_id');
    }
}
