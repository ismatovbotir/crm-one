<?php

namespace Database\Factories;

use App\Enums\IntegrationAbility;
use App\Models\IntegrationToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tokens with a random plain value that is not recoverable; tests needing the plain value use
 * IntegrationToken::issue() instead.
 *
 * @extends Factory<IntegrationToken>
 */
class IntegrationTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plain = IntegrationToken::PREFIX.Str::random(48);

        return [
            'name' => '1C '.fake()->word(),
            'token_prefix' => substr($plain, 0, IntegrationToken::VISIBLE_PREFIX_LENGTH),
            'token_hash' => IntegrationToken::hashToken($plain),
            'abilities' => IntegrationAbility::values(),
            'allowed_ips' => null,
            'signing_secret' => null,
            'expires_at' => now()->addDays(180),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['revoked_at' => now()]);
    }
}
