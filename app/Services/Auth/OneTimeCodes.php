<?php

namespace App\Services\Auth;

use App\Models\LoginCode;
use App\Models\TenderInvitationLink;
use App\Models\User;
use App\Services\Auth\Exceptions\OneTimeCodeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One-time codes (Spec 03 4.3 rules, reused by Spec 08 bid-link verification): 6 digits from
 * random_int, HMAC-SHA256 with the app key at rest, valid 10 minutes, 5 attempts, 60 s
 * cooldown between sends, a new code invalidates the previous one, constant-time comparison.
 * The caller delivers the plain code (e-mail/Telegram); it is never stored or logged here.
 */
class OneTimeCodes
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const COOLDOWN_SECONDS = 60;

    /**
     * @return string the plain code to deliver
     */
    public function issue(string $purpose, string $channel, ?User $user = null, ?TenderInvitationLink $link = null): string
    {
        return DB::transaction(function () use ($purpose, $channel, $user, $link): string {
            $query = $this->scope($purpose, $user, $link);
            $last = (clone $query)->latest('id')->lockForUpdate()->first();

            if ($last !== null && $last->created_at !== null && $last->created_at->greaterThan(now()->subSeconds(self::COOLDOWN_SECONDS))) {
                throw new OneTimeCodeException('cooldown', ['seconds' => self::COOLDOWN_SECONDS]);
            }

            (clone $query)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now()]);
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            LoginCode::query()->create([
                'user_id' => $user?->id,
                'tender_invitation_link_id' => $link?->id,
                'purpose' => $purpose,
                'channel' => $channel,
                'code_hash' => self::hash($code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'ip' => request()?->ip(),
                'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
            ]);

            return $code;
        });
    }

    /**
     * True and consumed when the code matches the latest usable code; wrong codes count attempts
     * and the code is invalidated after the fifth.
     */
    public function verify(string $purpose, string $code, ?User $user = null, ?TenderInvitationLink $link = null): bool
    {
        return DB::transaction(function () use ($purpose, $code, $user, $link): bool {
            $row = $this->scope($purpose, $user, $link)->whereNull('consumed_at')->whereNull('invalidated_at')->latest('id')->lockForUpdate()->first();

            if ($row === null || ! $row->isUsable()) {
                return false;
            }

            if (hash_equals($row->code_hash, self::hash(preg_replace('/\D/', '', $code) ?? ''))) {
                $row->forceFill(['consumed_at' => now()])->save();

                return true;
            }

            $attempts = $row->attempts + 1;
            $row->forceFill(['attempts' => $attempts, 'invalidated_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null])->save();

            return false;
        });
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /**
     * @return Builder<LoginCode>
     */
    protected function scope(string $purpose, ?User $user, ?TenderInvitationLink $link): Builder
    {
        return LoginCode::query()->where('purpose', $purpose)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->whereNull('user_id'))
            ->when($link !== null, fn ($q) => $q->where('tender_invitation_link_id', $link->id), fn ($q) => $q->whereNull('tender_invitation_link_id'));
    }
}
