<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram person the bot has seen (Spec 04 section 17), identified by Telegram user id only;
 * names are display data and never used for matching. Linked to a CRM user (staff or portal, by
 * the Telegram id of their TelegramLink) and to at most one customer contact (one company at a time).
 */
#[Fillable(['telegram_user_id', 'first_name', 'last_name', 'username', 'language_code', 'is_bot', 'phone', 'user_id', 'contact_id'])]
class TelegramContact extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['telegram_user_id' => 'integer', 'is_bot' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return HasMany<TelegramChatMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TelegramChatMember::class);
    }

    public function fullName(): string
    {
        $name = trim(((string) $this->first_name).' '.((string) $this->last_name));

        return $name !== '' ? $name : ($this->username ? '@'.$this->username : 'Telegram '.$this->telegram_user_id);
    }
}
