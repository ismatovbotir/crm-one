<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare offer rows for tests. Content (lines, groups) should be added through the Actions so
 * snapshots and totals are computed like in the app.
 *
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'OF-'.now()->format('Y').'-'.fake()->unique()->numerify('9####'),
            'version' => 1,
            'status' => OfferStatus::Draft,
            'customer_id' => Customer::factory(),
            'owner_id' => User::factory(),
            'currency' => 'UZS',
            'prices_include_vat' => false,
            'valid_until' => now(config('offers.timezone'))->addDays(14)->toDateString(),
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => ['owner_id' => $owner->id]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => ['customer_id' => $customer->id, 'currency' => $customer->currency]);
    }

    public function status(OfferStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
