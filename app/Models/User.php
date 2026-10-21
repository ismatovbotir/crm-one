<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Locale;
use App\Enums\UserRole;
use App\Enums\UserType;
use App\Models\Concerns\LogsActivity;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use InvalidArgumentException;

/**
 * @property UserType $type
 * @property UserRole|null $role
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'type', 'phone', 'position', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /**
     * Reserved domain (RFC 2606) of portal users prepared from a Telegram group member (Spec 04
     * section 17): they have no e-mail; such addresses are never mailed.
     */
    public const TELEGRAM_EMAIL_DOMAIN = 'telegram.invalid';

    /** Mail notifications never go to the Telegram placeholder address. */
    public function routeNotificationForMail(): ?string
    {
        return $this->hasDeliverableEmail() ? (string) $this->email : null;
    }

    /** False for empty addresses and the Telegram placeholder. */
    public function hasDeliverableEmail(): bool
    {
        return filled($this->email) && ! str_ends_with(mb_strtolower((string) $this->email), '@'.self::TELEGRAM_EMAIL_DOMAIN);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'internal',
        'role' => 'sales',
        'is_active' => true,
    ];

    /**
     * Spec 03 DEC-9: portal users never carry a global role; internal users always do.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->type === UserType::Portal) {
                $user->role = null;

                return;
            }

            if ($user->role === null) {
                throw new InvalidArgumentException('Internal users must have a role.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'type' => UserType::class,
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'locale' => Locale::class,
            'notification_preferences' => 'array',
            'ui_preferences' => 'array',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Department, $this, DepartmentMember, 'membership'>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)
            ->using(DepartmentMember::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * Memberships in active departments; loaded once per request (changes apply on the next request).
     *
     * @return Collection<int, Department>
     */
    public function activeDepartments(): Collection
    {
        if (! $this->relationLoaded('departments')) {
            $this->load(['departments' => fn ($query) => $query->orderBy('sort_order')]);
        }

        return $this->departments->filter(fn (Department $department): bool => $department->is_active && ! $department->trashed())->values();
    }

    public function primaryDepartment(): ?Department
    {
        return $this->activeDepartments()->first(fn (Department $department): bool => $department->membership->is_primary);
    }

    public function isInternal(): bool
    {
        return $this->type === UserType::Internal;
    }

    public function isPortal(): bool
    {
        return $this->type === UserType::Portal;
    }

    /**
     * @return HasOne<TelegramLink, $this>
     */
    public function telegramLink(): HasOne
    {
        return $this->hasOne(TelegramLink::class);
    }

    /**
     * @return HasMany<PortalMembership, $this>
     */
    public function portalMemberships(): HasMany
    {
        return $this->hasMany(PortalMembership::class);
    }

    /** Phase 1: the single active customer membership of a portal user. */
    public function activeMembership(): ?PortalMembership
    {
        return $this->portalMemberships()->where('is_active', true)->whereNotNull('customer_id')
            ->whereHas('customer', fn ($q) => $q->where('portal_enabled', true)->whereNull('deleted_at'))
            ->with('customer')->first();
    }

    /**
     * Global role check. Always false for portal users, even if a role was set directly in the DB.
     */
    public function hasRole(UserRole $role): bool
    {
        return $this->isInternal() && $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(UserRole::Admin);
    }

    /**
     * Two-letter initials for the avatar badge.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<User>  $query
     */
    public function scopeInternal(Builder $query): void
    {
        $query->where('type', UserType::Internal);
    }

    /**
     * @param  list<string>  $changedKeys
     */
    protected function activityEventForUpdate(array $changedKeys): string
    {
        if (in_array('role', $changedKeys, true)) {
            return 'role_changed';
        }

        $activationKeys = ['is_active', 'deactivated_at', 'deactivated_by'];

        if (in_array('is_active', $changedKeys, true) && array_diff($changedKeys, $activationKeys) === []) {
            return $this->is_active ? 'activated' : 'deactivated';
        }

        return 'updated';
    }

    /**
     * @return list<string>
     */
    protected function activityExcept(): array
    {
        return ['last_login_at', 'email_verified_at', 'locale'];
    }

    /** Per-user UI choice (users.ui_preferences), e.g. `lead_form_single_page`. */
    public function uiPreference(string $key, mixed $default = null): mixed
    {
        return ($this->ui_preferences ?? [])[$key] ?? $default;
    }

    public function setUiPreference(string $key, mixed $value): void
    {
        $this->forceFill(['ui_preferences' => [...($this->ui_preferences ?? []), $key => $value]])->save();
    }
}
