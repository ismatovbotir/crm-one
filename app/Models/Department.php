<?php

namespace App\Models;

use App\Enums\DepartmentRole;
use App\Enums\Locale;
use App\Models\Concerns\LogsActivity;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Internal department (Spec 03 section 7.2). Seeded departments have no stored name and
 * fall back to `departments.names.<code>`; admins may override the name per language.
 *
 * @property array<string, string|null>|null $name
 */
#[Fillable(['code', 'name', 'parent_id', 'is_active', 'sort_order'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, DepartmentMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(DepartmentMember::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'is_primary'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<DepartmentMember, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(DepartmentMember::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @param  Builder<Department>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function head(): ?User
    {
        return $this->members->first(fn (User $user): bool => $user->membership->role === DepartmentRole::Head);
    }

    /**
     * Name in the given (default: current) language, then the seeded translation, then the code.
     */
    public function displayName(?Locale $locale = null): string
    {
        $locale ??= Locale::current();
        $stored = $this->name[$locale->value] ?? null;

        if (filled($stored)) {
            return $stored;
        }

        $key = 'departments.names.'.$this->code;

        if (trans()->has($key)) {
            return __($key);
        }

        foreach ((array) $this->name as $value) {
            if (filled($value)) {
                return $value;
            }
        }

        return (string) $this->code;
    }
}
