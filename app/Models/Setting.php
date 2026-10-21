<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Application-wide key/value settings. Reads go through a forever cache that is
 * flushed whenever a setting is written or removed.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    public const CACHE_KEY = 'settings.all';

    /** Setting key holding the uploaded logo path on the public disk. */
    public const LOGO_KEY = 'branding.logo';

    public const DEFAULT_LOGO = 'images/logo.jpg';

    public const LOGO_DIRECTORY = 'branding';

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * All settings as key => value, cached (memoized per request: one cache read per request).
     *
     * @return array<string, string|null>
     */
    public static function allCached(): array
    {
        return Cache::memo()->rememberForever(self::CACHE_KEY, fn (): array => static::query()->pluck('value', 'key')->all());
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::allCached()[$key] ?? $default;
    }

    /**
     * Write several settings at once, attributing the change to a user.
     *
     * @param  array<string, string|null>  $values
     */
    public static function putMany(array $values, ?User $user = null): void
    {
        DB::transaction(function () use ($values, $user): void {
            foreach ($values as $key => $value) {
                static::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $user?->id]);
            }
        });

        static::flushCache();
    }

    /**
     * @param  list<string>  $keys
     */
    public static function forget(array $keys): void
    {
        static::query()->whereIn('key', $keys)->delete();

        static::flushCache();
    }

    public static function flushCache(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }

    /**
     * URL of the company logo: the uploaded one (public disk) or the bundled RSG logo.
     */
    public static function logoUrl(): string
    {
        return static::hasCustomLogo() ? asset('storage/'.static::get(self::LOGO_KEY)) : asset(self::DEFAULT_LOGO);
    }

    /**
     * Only raster logos are ever served; a legacy SVG path stored before the upload
     * hardening falls back to the default logo instead of being linked.
     */
    public static function hasCustomLogo(): bool
    {
        $path = static::get(self::LOGO_KEY);

        return filled($path) && preg_match('/\.(png|jpe?g|webp)$/i', $path) === 1;
    }
}
