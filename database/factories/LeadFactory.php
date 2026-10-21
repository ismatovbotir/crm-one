<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'number' => sprintf('LD-%d-%05d', (int) now()->format('Y'), 90000 + $sequence),
            'status' => LeadStatus::New,
            'source' => LeadSource::Phone,
            'owner_id' => User::factory()->sales(),
            'company_name' => fake()->company(),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->numerify('+99890#######'),
            'need' => fake()->sentence(),
            'currency' => 'UZS',
            'last_activity_at' => now(),
        ];
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes) => ['owner_id' => $owner->id]);
    }

    /**
     * Linked to a customer and its contact with BANT confirmed (ready to qualify).
     */
    public function qualifiable(?Customer $customer = null): static
    {
        return $this->state(function (array $attributes) use ($customer) {
            $customer ??= Customer::factory()->create(['currency' => 'UZS']);
            $contact = Contact::factory()->create(['partner_id' => $customer->id]);

            return [
                'customer_id' => $customer->id,
                'contact_id' => $contact->id,
                'budget_confirmed' => true,
                'authority_confirmed' => true,
                'need_confirmed' => true,
                'timeline_confirmed' => true,
            ];
        });
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }
}
