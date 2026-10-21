<?php

namespace App\Models;

use App\Enums\IntegrationAbility;
use App\Models\Concerns\LogsActivity;
use Carbon\CarbonImmutable;
use Database\Factories\IntegrationTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Machine token for the 1C inbound API (Spec 02 section 11.4, BR-I.18/I.18a, DEC-1).
 *
 * The plain token (`1c_` + 48 random chars) is returned once by issue() and never stored:
 * only its sha256 is kept and looked up. Abilities, optional IP/CIDR allowlist, optional
 * HMAC signing secret (encrypted), mandatory expiry, revocation.
 *
 * @property list<string> $abilities
 * @property list<string>|null $allowed_ips
 * @property string|null $signing_secret
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable(['name', 'token_prefix', 'token_hash', 'abilities', 'allowed_ips', 'signing_secret', 'expires_at', 'created_by'])]
#[Hidden(['token_hash', 'signing_secret'])]
class IntegrationToken extends Model
{
    /** @use HasFactory<IntegrationTokenFactory> */
    use HasFactory, LogsActivity;

    public const PREFIX = '1c_';

    /** Characters of the plain token kept for identification in the UI and logs. */
    public const VISIBLE_PREFIX_LENGTH = 11;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'allowed_ips' => 'array',
            'signing_secret' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Creates a token and returns it with the plain value (and signing secret) to show once.
     *
     * @param  list<string>  $abilities
     * @param  list<string>|null  $allowedIps
     * @return array{token: self, plain: string, signing_secret: string|null}
     */
    public static function issue(string $name, array $abilities, ?array $allowedIps = null, ?int $days = null, bool $withSigningSecret = false, ?User $creator = null, ?string $plain = null): array
    {
        $days ??= (int) config('onec.inbound.token_default_days');
        $maxDays = (int) config('onec.inbound.token_max_days');

        if ($days < 1 || $days > $maxDays) {
            throw new InvalidArgumentException("Token lifetime must be 1..{$maxDays} days.");
        }

        $abilities = array_values(array_intersect(IntegrationAbility::values(), $abilities));

        if ($abilities === []) {
            throw new InvalidArgumentException('A token needs at least one ability.');
        }

        foreach ($allowedIps ?? [] as $entry) {
            if (! self::isValidIpOrCidr($entry)) {
                throw new InvalidArgumentException("Invalid IP or CIDR [{$entry}].");
            }
        }

        $plain ??= self::PREFIX.Str::random(48);
        $secret = $withSigningSecret ? Str::random(64) : null;

        $token = self::query()->create([
            'name' => $name,
            'token_prefix' => substr($plain, 0, self::VISIBLE_PREFIX_LENGTH),
            'token_hash' => self::hashToken($plain),
            'abilities' => $abilities,
            'allowed_ips' => blank($allowedIps) ? null : array_values($allowedIps),
            'signing_secret' => $secret,
            'expires_at' => now()->addDays($days),
            'created_by' => $creator?->id,
        ]);

        return ['token' => $token, 'plain' => $plain, 'signing_secret' => $secret];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Active (not revoked, not expired) token for a presented plain value, or null.
     */
    public static function findActive(string $plain): ?self
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 128) {
            return null;
        }

        $token = self::query()->where('token_hash', self::hashToken($plain))->first();

        return $token?->isActive() ? $token : null;
    }

    public static function isValidIpOrCidr(string $entry): bool
    {
        [$ip, $bits] = array_pad(explode('/', trim($entry), 2), 2, null);

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($bits === null) {
            return true;
        }

        $max = str_contains((string) $ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<IntegrationToken>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    public function hasAbility(IntegrationAbility|string $ability): bool
    {
        $value = $ability instanceof IntegrationAbility ? $ability->value : $ability;

        return in_array($value, $this->abilities ?? [], true);
    }

    public function allowsIp(?string $ip): bool
    {
        if (blank($this->allowed_ips)) {
            return true;
        }

        return $ip !== null && IpUtils::checkIp($ip, $this->allowed_ips);
    }

    public function requiresSignature(): bool
    {
        return filled($this->signing_secret);
    }

    public function revoke(?User $by = null): void
    {
        if ($this->revoked_at !== null) {
            return;
        }

        $this->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id])->save();
    }

    /**
     * Records use at most once per minute per IP (no write on every request).
     */
    public function recordUse(?string $ip): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(now()->subMinute()) && $this->last_used_ip === $ip) {
            return;
        }

        $this->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
    }

    protected function activityEventForCreate(): string
    {
        return 'api_token_created';
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        return in_array('revoked_at', $changedKeys, true) ? 'api_token_revoked' : 'updated';
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['token_hash', 'signing_secret', 'last_used_at', 'last_used_ip'];
    }
}
