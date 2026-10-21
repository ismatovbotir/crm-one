<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare order rows for list/policy tests. Business tests create orders through
 * CreateOrderFromOffer (tests/Concerns/BuildsOrders) so lines and totals are real.
 *
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'OR-'.now()->format('Y').'-'.fake()->unique()->numerify('9####'),
            'status' => OrderStatus::New,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'offer_version' => 1,
            'customer_id' => Customer::factory(),
            'owner_id' => User::factory(),
            'currency' => 'UZS',
            'prices_include_vat' => false,
            'net_minor' => 100000,
            'vat_minor' => 12000,
            'gross_minor' => 112000,
            'agreement_required' => true,
            // Last, so owner and customer are already resolved.
            'offer_id' => fn (array $attributes) => Offer::factory()->status(OfferStatus::Accepted)->create([
                'owner_id' => $attributes['owner_id'],
                'customer_id' => $attributes['customer_id'],
            ])->id,
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => ['owner_id' => $owner->id]);
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
