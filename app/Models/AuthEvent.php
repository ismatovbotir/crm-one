<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only authentication audit (Spec 03 4.3): otp_requested, otp_sent, otp_send_failed,
 * otp_verified, otp_failed, otp_locked, login, logout. Never the code; unknown identifiers only
 * as a keyed hash.
 */
#[Fillable(['user_id', 'guard', 'event', 'channel', 'ip', 'user_agent', 'identifier_hash', 'created_at'])]
class AuthEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Auth events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Auth events are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    public static function record(string $guard, string $event, ?User $user = null, ?string $channel = null, ?string $identifier = null): void
    {
        self::query()->create([
            'user_id' => $user?->id,
            'guard' => $guard,
            'event' => $event,
            'channel' => $channel,
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
            'identifier_hash' => $identifier !== null ? hash_hmac('sha256', mb_strtolower(trim($identifier)), (string) config('app.key')) : null,
            'created_at' => now(),
        ]);
    }
}
