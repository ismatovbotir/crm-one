<?php

namespace App\Models;

use App\Enums\TelegramBotStatus;
use App\Enums\TelegramChatPurpose;
use App\Enums\TelegramChatType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram chat the bot is or was in (Spec 04 section 17). Groups may be linked to one customer
 * and carry their single open lead. Merged duplicates point to the chat they were merged into.
 *
 * @property TelegramChatType $type
 * @property TelegramBotStatus $bot_status
 * @property CarbonImmutable|null $last_message_at
 */
#[Fillable(['chat_id', 'type', 'title', 'username', 'bot_status', 'member_count', 'members_refreshed_at', 'first_seen_at', 'last_message_at', 'customer_id', 'lead_id', 'merged_into_id', 'purpose'])]
class TelegramChat extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['purpose' => 'customer'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'type' => TelegramChatType::class,
            'purpose' => TelegramChatPurpose::class,
            'bot_status' => TelegramBotStatus::class,
            'member_count' => 'integer',
            'members_refreshed_at' => 'immutable_datetime',
            'first_seen_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<TelegramChat, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /**
     * @return HasMany<TelegramChatMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TelegramChatMember::class);
    }

    /**
     * @param  Builder<TelegramChat>  $query
     */
    public function scopeGroups(Builder $query): void
    {
        $query->whereIn('type', [TelegramChatType::Group->value, TelegramChatType::Supergroup->value]);
    }

    public function isGroup(): bool
    {
        return $this->type->isGroup();
    }

    /** Company group for internal notifications (Spec 03 section 23): never creates leads. */
    public function isInternal(): bool
    {
        return $this->purpose === TelegramChatPurpose::InternalNotifications;
    }

    /**
     * @param  Builder<TelegramChat>  $query
     */
    public function scopeCustomerChats(Builder $query): void
    {
        $query->where('purpose', TelegramChatPurpose::Customer->value);
    }

    /** Public link (t.me/username) when the group has a public username. */
    public function link(): ?string
    {
        return filled($this->username) ? 'https://t.me/'.$this->username : null;
    }

    public function displayName(): string
    {
        return (string) ($this->title ?: ($this->username ? '@'.$this->username : 'Telegram '.$this->chat_id));
    }
}
