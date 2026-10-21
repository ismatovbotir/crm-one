<?php

namespace Database\Factories;

use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\TemplateStatus;
use App\Models\Agreement;
use App\Models\AgreementTemplate;
use App\Models\AgreementTemplateVersion;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Bare agreement rows (framework kind by default) for list, policy and lifecycle tests. Business
 * tests generate agreements through GenerateAgreement.
 *
 * @extends Factory<Agreement>
 */
class AgreementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => 'AG-'.now()->format('Y').'-'.fake()->unique()->numerify('9####'),
            'kind' => AgreementKind::Framework,
            'status' => AgreementStatus::Draft,
            'customer_id' => Customer::factory(),
            'template_id' => fn () => $this->template()->id,
            'template_version' => 1,
            'document_locale' => 'ru',
            'variables' => [],
            'version' => 1,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->subDay()->toDateString(),
            'currency' => 'UZS',
        ];
    }

    public function status(AgreementStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    protected function template(): AgreementTemplate
    {
        $template = AgreementTemplate::query()->firstOrCreate(['code' => 'factory-framework'], ['name' => 'Factory framework', 'kind' => AgreementKind::Framework, 'is_active' => true]);

        if (! $template->versions()->exists()) {
            AgreementTemplateVersion::query()->create([
                'template_id' => $template->id, 'version' => 1, 'locale' => 'ru', 'status' => TemplateStatus::Published,
                'body' => '<p>{{ agreement.number }}</p>', 'checksum' => Str::random(64),
            ]);
        }

        return $template;
    }
}
