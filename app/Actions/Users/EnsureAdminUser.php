<?php

namespace App\Actions\Users;

use App\Enums\UserRole;
use App\Enums\UserType;
use App\Models\User;
use InvalidArgumentException;

/**
 * Creates or recovers the default administrator from the environment (`ADMIN_NAME`, `ADMIN_EMAIL`,
 * `ADMIN_PASSWORD` via config/crm.php), for a fresh install or a lost admin password:
 * - no user with that e-mail: the administrator is created with id 1 when that id is free, otherwise
 *   with the next free id (an existing user 1 is never overwritten);
 * - a user with that e-mail exists: the password is reset to the one in the environment, and the
 *   account is made an active internal administrator again.
 * Run `php artisan crm:admin`.
 */
class EnsureAdminUser
{
    /**
     * @return array{user: User, created: bool, id_one_taken: bool, changes: list<string>}
     */
    public function handle(?string $name, ?string $email, ?string $password): array
    {
        $email = mb_strtolower(trim((string) $email));
        $name = trim((string) $name) !== '' ? trim((string) $name) : 'Administrator';

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('ADMIN_EMAIL is missing or not a valid e-mail address.');
        }

        if (mb_strlen((string) $password) < 8) {
            throw new InvalidArgumentException('ADMIN_PASSWORD is missing or shorter than 8 characters.');
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $idFree = ! User::query()->whereKey(1)->exists();
            $user = new User(['name' => $name, 'email' => $email, 'password' => $password, 'role' => UserRole::Admin, 'type' => UserType::Internal, 'is_active' => true]);

            if ($idFree) {
                $user->forceFill(['id' => 1]);
            }

            $user->forceFill(['email_verified_at' => now()])->save();

            return ['user' => $user, 'created' => true, 'id_one_taken' => ! $idFree, 'changes' => []];
        }

        $changes = ['password'];

        if ($user->role !== UserRole::Admin) {
            $changes[] = 'role';
        }

        if (! $user->is_active) {
            $changes[] = 'activated';
        }

        if ($user->type !== UserType::Internal) {
            $changes[] = 'type';
        }

        $user->forceFill(['password' => $password, 'role' => UserRole::Admin, 'type' => UserType::Internal, 'is_active' => true, 'deactivated_at' => null, 'deactivated_by' => null, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

        return ['user' => $user, 'created' => false, 'id_one_taken' => false, 'changes' => $changes];
    }
}
