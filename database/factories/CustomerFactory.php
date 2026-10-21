<?php

namespace Database\Factories;

use App\Enums\CustomerOrigin;
use App\Enums\LegalType;
use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'origin' => CustomerOrigin::Crm,
            'name' => fake()->unique()->company(),
            'legal_name' => null,
            'legal_type' => LegalType::LegalEntity,
            'tax_id' => fake()->unique()->numerify('3########'),
            'country' => 'UZ',
            'city' => fake()->city(),
            'address' => fake()->streetAddress(),
            'phone' => fake()->unique()->numerify('+99890#######'),
            'email' => fake()->unique()->companyEmail(),
            'currency' => 'UZS',
            'payment_terms_days' => null,
            'owner_id' => User::factory(),
            'sync_status' => SyncStatus::NotSynced,
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => ['owner_id' => $owner->id]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['deleted_at' => now()]);
    }

    /**
     * "Sync with 1C" switched on (Spec 02 rev. 4; off by default).
     */
    public function syncing(): static
    {
        return $this->state(fn (array $attributes) => ['sync_with_1c' => true]);
    }

    /**
     * A customer pulled from 1C (linked by GUID).
     */
    public function fromOneC(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => CustomerOrigin::Onec,
            'sync_with_1c' => true,
            'sync_status' => SyncStatus::Synced,
            'external_id' => (string) Str::uuid(),
            'external_code' => fake()->numerify('0000#####'),
            'synced_at' => now(),
        ]);
    }
}
