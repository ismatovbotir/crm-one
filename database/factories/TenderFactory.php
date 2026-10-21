<?php

namespace Database\Factories;

use App\Enums\TenderKind;
use App\Enums\TenderMethod;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Models\Tender;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare tender header for list/visibility tests. Full drafts (lots, spec, criteria) come from
 * App\Actions\Tenders\ManageTender::create (see Tests\Concerns\BuildsTenders).
 *
 * @extends Factory<Tender>
 */
class TenderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'TT-2026-'.fake()->unique()->numerify('#####'),
            'type' => TenderType::Transport,
            'status' => TenderStatus::Draft,
            'method' => TenderMethod::Rfq,
            'kind' => TenderKind::Spot,
            'title' => fake()->sentence(4),
            'notice_locale' => 'ru',
            'evaluation_currency' => 'UZS',
            'accepted_currencies' => ['UZS', 'USD'],
            'bid_validity_days' => 30,
        ];
    }

    public function purchase(): static
    {
        return $this->state(fn (): array => ['type' => TenderType::Purchase, 'number' => 'PT-2026-'.fake()->unique()->numerify('#####')]);
    }
}
