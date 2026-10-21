<?php

namespace Tests\Feature;

use App\Livewire\Help\Guide;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * In-app user guide (roadmap step 10): opens on the reader's role, every section is translated in
 * uz/ru/en, and links appear only for pages the reader may open.
 */
class GuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_opens_on_the_users_role_and_is_in_the_sidebar(): void
    {
        $stock = User::factory()->stockManager()->create();

        $this->actingAs($stock)->get(route('dashboard'))->assertSee(route('guide'), false);

        Livewire::actingAs($stock)->test(Guide::class)
            ->assertSet('section', 'stock_manager')
            ->assertSee(__('guide.sections.stock_manager.steps.packaging.title'))
            ->assertSee(route('packaging.index'), false)
            ->set('section', 'admin')
            ->assertSee(__('guide.sections.admin.steps.setup.title'))
            ->assertDontSee(route('settings.setup'), false);
    }

    public function test_unknown_role_falls_back_to_general_and_every_section_is_translated(): void
    {
        $staff = User::factory()->staff()->create();
        Livewire::actingAs($staff)->test(Guide::class, ['section' => 'nope'])->assertSet('section', 'staff');

        foreach (['uz', 'ru', 'en'] as $locale) {
            foreach (Guide::SECTIONS as $section => $steps) {
                foreach (array_keys($steps) as $step) {
                    foreach (['title', 'text'] as $field) {
                        $key = "guide.sections.{$section}.steps.{$step}.{$field}";
                        $this->assertNotSame($key, __($key, [], $locale), "Missing {$locale} {$key}");
                    }
                }
            }
        }
    }

    public function test_guide_text_keeps_the_pull_only_1c_wording(): void
    {
        foreach (['uz', 'ru', 'en'] as $locale) {
            $text = mb_strtolower((string) json_encode(trans('guide', [], $locale), JSON_UNESCAPED_UNICODE));
            $this->assertStringNotContainsString('sent to 1c', $text);
            $this->assertStringNotContainsString('push to 1c', $text);
        }
    }
}
