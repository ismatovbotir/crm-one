<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * UI consistency step 1: skip link and main landmark, keyboard tabs, modal focus handling, no raw
 * `{!! !!}` output in views, pagination from config.
 */
class AccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_have_a_skip_link_to_the_main_landmark_and_keyboard_tabs(): void
    {
        $item = Item::factory()->create();

        $this->actingAs(User::factory()->manager()->create())->get(route('items.show', $item))->assertOk()
            ->assertSeeInOrder(['class="skip-link"', 'id="main-content"'], false)
            ->assertSee('x-data="tabList"', false);

        $this->get(route('portal.login'))->assertSee('href="#main-content"', false);
    }

    public function test_every_tablist_is_keyboard_enabled_and_dialogs_trap_focus(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $content = $file->getContents();

            if (str_contains($content, 'role="tablist"')) {
                $this->assertSame(substr_count($content, 'role="tablist"'), substr_count($content, 'x-data="tabList"'), $file->getRelativePathname().' has a tablist without keyboard support.');
            }

            if (str_contains($content, 'aria-modal="true"')) {
                $this->assertStringContainsString('x-data="modalDialog"', $content, $file->getRelativePathname().' has a modal without focus handling.');
            }

            $this->assertStringNotContainsString('{!!', $content, $file->getRelativePathname().' prints raw HTML.');
        }
    }

    public function test_lists_paginate_by_the_configured_page_size(): void
    {
        foreach (File::allFiles(app_path('Livewire')) as $file) {
            $this->assertDoesNotMatchRegularExpression('/->paginate\(\d+\)/', $file->getContents(), $file->getRelativePathname().' hard-codes the page size.');
        }
    }
}
