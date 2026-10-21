<?php

namespace Database\Factories;

use App\Enums\DepartmentRole;
use App\Enums\UserRole;
use App\Enums\UserType;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Sales,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    public function manager(): static
    {
        return $this->role(UserRole::Manager);
    }

    public function sales(): static
    {
        return $this->role(UserRole::Sales);
    }

    public function finance(): static
    {
        return $this->role(UserRole::Finance);
    }

    public function accountant(): static
    {
        return $this->role(UserRole::Accountant);
    }

    public function stockManager(): static
    {
        return $this->role(UserRole::StockManager);
    }

    public function staff(): static
    {
        return $this->role(UserRole::Staff);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * A customer/partner portal user: no global role, no password (Spec 03 DEC-5, DEC-9).
     */
    public function portal(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => UserType::Portal,
            'role' => null,
            'password' => null,
        ]);
    }

    /**
     * Membership in a seeded department (by code) or a given department, e.g. ->inDepartment('logistics', DepartmentRole::Head).
     */
    public function inDepartment(Department|string $department, DepartmentRole $role = DepartmentRole::Specialist, bool $primary = true): static
    {
        return $this->afterCreating(function (User $user) use ($department, $role, $primary): void {
            $model = $department instanceof Department ? $department : Department::query()->where('code', $department)->firstOrFail();

            $user->departments()->attach($model->id, ['role' => $role->value, 'is_primary' => $primary]);
            $user->unsetRelation('departments');
        });
    }
}
