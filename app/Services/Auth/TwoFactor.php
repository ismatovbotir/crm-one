<?php

namespace App\Services\Auth;

use App\Models\AuthEvent;
use App\Models\User;
use App\Support\BusinessSettings;
use App\Support\Totp;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Staff two-factor authentication (roadmap step 6): TOTP with an encrypted secret, 8 single-use
 * recovery codes stored hashed, no code reuse (last accepted time step), every change and use
 * written to `auth_events`. Required for the roles in `security.two_factor_roles` (admin,
 * accountant, finance) when `security.enforce_two_factor` is on.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null && filled($user->two_factor_secret);
    }

    public function required(User $user): bool
    {
        return $user->isInternal()
            && BusinessSettings::bool('security.enforce_two_factor')
            && in_array($user->role?->value, (array) config('security.two_factor_roles'), true);
    }

    /**
     * Confirms a new secret with a first code; returns the plain recovery codes (shown once).
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $secret, string $code): ?array
    {
        $step = Totp::verify($secret, $code);

        if ($step === null) {
            AuthEvent::record('web', '2fa_confirm_failed', $user);

            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString((string) json_encode(array_map(fn (string $plain): string => Hash::make($plain), $codes))),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();
        AuthEvent::record('web', '2fa_enabled', $user);

        return $codes;
    }

    /**
     * A TOTP code (not reused) or an unused recovery code (consumed).
     */
    public function verify(User $user, string $code): bool
    {
        if (! $this->enabled($user)) {
            return false;
        }

        $code = trim($code);
        $step = Totp::verify($this->secret($user), $code);

        if ($step !== null && $step > (int) $user->two_factor_last_step) {
            $user->forceFill(['two_factor_last_step' => $step])->saveQuietly();
            AuthEvent::record('web', '2fa_passed', $user);

            return true;
        }

        if ($this->useRecoveryCode($user, $code)) {
            AuthEvent::record('web', '2fa_recovery_used', $user);

            return true;
        }

        AuthEvent::record('web', '2fa_failed', $user);

        return false;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString((string) json_encode(array_map(fn (string $plain): string => Hash::make($plain), $codes)))])->save();
        AuthEvent::record('web', '2fa_codes_regenerated', $user);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
        AuthEvent::record('web', '2fa_disabled', $user);
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->hashes($user));
    }

    protected function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(str_replace(' ', '', $code));

        if (preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code) !== 1) {
            return false;
        }

        $hashes = $this->hashes($user);

        foreach ($hashes as $index => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => Crypt::encryptString((string) json_encode(array_values($hashes)))])->saveQuietly();

                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    protected function hashes(User $user): array
    {
        if (blank($user->two_factor_recovery_codes)) {
            return [];
        }

        return array_values((array) json_decode(Crypt::decryptString((string) $user->two_factor_recovery_codes), true));
    }

    protected function secret(User $user): string
    {
        return Crypt::decryptString((string) $user->two_factor_secret);
    }

    /**
     * @return list<string>
     */
    protected function newRecoveryCodes(): array
    {
        return array_map(fn (): string => strtoupper(Str::random(5).'-'.Str::random(5)), range(1, self::RECOVERY_CODES));
    }
}
