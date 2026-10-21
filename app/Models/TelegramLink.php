<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Telegram chat per user and one user per chat (Spec 03 DEC-7, BR-A.5). Blocked when the user
 * stopped the bot; then notifications fall back to e-mail and the Telegram OTP channel is off.
 * A link made by an administrator is pending until verified (Spec 03 section 17.10): pending links
 * get no notifications, no codes and no bot commands.
 *
 * @property CarbonImmutable|null $blocked_at
 */
#[Fillable(['user_id', 'chat_id', 'telegram_user_id', 'username', 'first_name', 'phone', 'linked_at', 'blocked_at', 'verified_at', 'verification_method', 'linked_by', 'force_reason'])]
class TelegramLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'telegram_user_id' => 'integer',
            'linked_at' => 'immutable_datetime',
            'blocked_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'verify_code_expires_at' => 'immutable_datetime',
            'verify_attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->blocked_at === null && $this->verified_at !== null;
    }

    public function isPending(): bool
    {
        return $this->verified_at === null;
    }

    /**
     * Not blocked and verified: may receive notifications and codes and run bot commands.
     *
     * @param  Builder<TelegramLink>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('blocked_at')->whereNotNull('verified_at');
    }
}
