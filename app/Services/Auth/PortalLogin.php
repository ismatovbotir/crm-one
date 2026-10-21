<?php

namespace App\Services\Auth;

use App\Enums\Locale;
use App\Enums\UserType;
use App\Models\AuthEvent;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Auth\Exceptions\OneTimeCodeException;
use App\Services\Telegram\Exceptions\TelegramException;
use App\Services\Telegram\TelegramClient;
use App\Support\CompanyProfile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Portal login by e-mail or Telegram one-time code (Spec 03 4.2/4.3, BR-A.2): the identifier is an
 * e-mail, a Telegram @username or a verified phone of a linked Telegram; only active portal
 * users with an active membership. Requests always get the same generic answer (no enumeration);
 * Telegram never falls back to e-mail automatically. Rate limits per identifier, user and IP;
 * verification attempts are limited per identifier across codes.
 */
class PortalLogin
{
    public const PURPOSE = 'portal_login';

    public function __construct(protected OneTimeCodes $codes, protected TelegramClient $telegram) {}

    /**
     * Sends a code when the identifier and channel are usable. Returns true only when a cooldown
     * blocked an otherwise valid request (the UI may then say "wait"), false otherwise.
     */
    public function requestCode(string $identifier, string $channel): bool
    {
        $identifier = trim($identifier);
        $ip = (string) request()->ip();

        foreach (['portal-otp-id|'.mb_strtolower($identifier) => 10, 'portal-otp-ip|'.$ip => 20] as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                AuthEvent::record('portal', 'otp_rate_limited', null, $channel, $identifier);

                return false;
            }

            RateLimiter::hit($key, 3600);
        }

        $user = $this->resolve($identifier);
        AuthEvent::record('portal', 'otp_requested', $user, $channel, $user === null ? $identifier : null);

        if ($user === null || ! in_array($channel, ['email', 'telegram'], true)) {
            return false;
        }

        $link = $channel === 'telegram' ? TelegramLink::query()->where('user_id', $user->id)->usable()->first() : null;

        if ($channel === 'telegram' && ($link === null || ! $this->telegram->enabled())) {
            return false;
        }

        if ($channel === 'email' && ! $user->hasDeliverableEmail()) {
            return false;
        }

        try {
            $code = $this->codes->issue(self::PURPOSE, $channel, $user);
        } catch (OneTimeCodeException) {
            return true;
        }

        $locale = $user->locale instanceof Locale ? $user->locale : Locale::default();

        try {
            if ($channel === 'email') {
                $this->mail((string) $user->email, $locale, $code);
            } else {
                $this->telegram->sendMessage($link->chat_id, $this->telegramText($locale, $code));
            }

            AuthEvent::record('portal', 'otp_sent', $user, $channel);
        } catch (TelegramException $exception) {
            if (in_array($exception->reason, ['bot_blocked', 'chat_not_found'], true)) {
                $link?->forceFill(['blocked_at' => now()])->save();
            }

            AuthEvent::record('portal', 'otp_send_failed', $user, $channel);
        }

        return false;
    }

    /**
     * Verifies the code and signs the user in on the portal guard (staff session dropped first).
     */
    public function verify(string $identifier, string $code, bool $remember = false): ?User
    {
        $key = 'portal-otp-verify|'.mb_strtolower(trim($identifier));
        $ipKey = 'portal-otp-verify-ip|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 10) || RateLimiter::tooManyAttempts($ipKey, 10)) {
            AuthEvent::record('portal', 'otp_locked', null, null, $identifier);

            return null;
        }

        RateLimiter::hit($key, 900);
        RateLimiter::hit($ipKey, 60);
        $user = $this->resolve($identifier);

        if ($user === null || ! $this->codes->verify(self::PURPOSE, $code, $user)) {
            AuthEvent::record('portal', 'otp_failed', $user, null, $user === null ? $identifier : null);

            return null;
        }

        RateLimiter::clear($key);
        Auth::guard('web')->logout();
        session()->regenerate();
        Auth::guard('portal')->login($user, $remember);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();
        // Spec 04 section 17: a portal user prepared from a Telegram group is activated by this first sign-in.
        $user->portalMemberships()->where('is_active', true)->whereNull('activated_at')->update(['activated_at' => now()]);
        AuthEvent::record('portal', 'login', $user);

        return $user;
    }

    /** Active portal user with an active membership for the identifier, or null. */
    public function resolve(string $identifier): ?User
    {
        $identifier = trim($identifier);
        $query = User::query()->where('type', UserType::Portal->value)->where('is_active', true);

        if (str_contains($identifier, '@') && ! str_starts_with($identifier, '@')) {
            $user = $query->whereRaw('lower(email) = ?', [mb_strtolower($identifier)])->first();
        } elseif (str_starts_with($identifier, '@')) {
            $userId = TelegramLink::query()->whereRaw('lower(username) = ?', [mb_strtolower(ltrim($identifier, '@'))])->value('user_id');
            $user = $userId !== null ? $query->whereKey($userId)->first() : null;
        } elseif (preg_match('/^\+?\d{9,15}$/', preg_replace('/[\s()-]/', '', $identifier) ?? '') === 1) {
            $phone = '+'.ltrim(preg_replace('/\D/', '', $identifier) ?? '', '+');
            $userId = TelegramLink::query()->where('phone', $phone)->value('user_id');
            $user = $userId !== null ? $query->whereKey($userId)->first() : null;
        } else {
            $user = null;
        }

        return $user?->activeMembership() !== null ? $user : null;
    }

    protected function mail(string $to, Locale $locale, string $code): void
    {
        $previous = App::getLocale();
        App::setLocale($locale->value);

        try {
            $company = CompanyProfile::seller($locale)['name'];
            Mail::send('mail.login-code', ['code' => $code, 'company' => $company], fn ($message) => $message->to($to)->subject(__('portal.mail.code_subject', ['company' => $company])));
        } finally {
            App::setLocale($previous);
        }
    }

    protected function telegramText(Locale $locale, string $code): string
    {
        return e(__('telegram.login_code', ['code' => $code, 'minutes' => OneTimeCodes::TTL_MINUTES], $locale->value));
    }
}
