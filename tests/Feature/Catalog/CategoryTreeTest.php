<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Catalog\Categories;
use App\Models\Category;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 01 section 6.6 / AC-2.5: CRM-created categories nest at most 3 levels; 1C groups mirror
 * any depth (Spec 02 BR-I.13); no category ever forms a cycle.
 */
class CategoryTreeTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_levels_are_allowed_and_a_fourth_crm_category_is_rejected(): void
    {
        $a = Category::factory()->crmCreated()->create();
        $b = Category::factory()->crmCreated()->childOf($a)->create();
        $c = Category::factory()->crmCreated()->childOf($b)->create();

        $this->assertSame(3, $c->depth());

        $this->expectException(DomainException::class);
        Category::factory()->crmCreated()->childOf($c)->create();
    }

    public function test_moving_a_crm_subtree_cannot_exceed_the_depth_limit(): void
    {
        $a = Category::factory()->crmCreated()->create();
        $b = Category::factory()->crmCreated()->childOf($a)->create();
        $x = Category::factory()->crmCreated()->create();
        Category::factory()->crmCreated()->childOf($x)->create();

        $this->expectException(DomainException::class);
        $x->update(['parent_id' => $b->id]);
    }

    public function test_one_c_groups_are_exempt_from_the_depth_limit(): void
    {
        $parent = null;

        foreach (range(1, 6) as $level) {
            $parent = Category::factory()->when($parent, fn ($factory) => $factory->childOf($parent))->create(['name' => 'Level '.$level]);
        }

        $this->assertSame(6, $parent->depth());
        $this->assertCount(6, Category::withDescendantIds(Category::query()->roots()->value('id')));

        $this->actingAs(User::factory()->sales()->create());
        Livewire::test(Categories::class)
            ->assertSeeInOrder(['Level 1', 'Level 6'])
            ->assertSee('aria-level="6"', false);
    }

    public function test_cycles_are_rejected_for_deep_one_c_groups_too(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->childOf($a)->create();
        $c = Category::factory()->childOf($b)->create();
        $d = Category::factory()->childOf($c)->create();

        $this->expectException(DomainException::class);
        $a->update(['parent_id' => $d->id]);
    }

    /** AC-2.5 */
    public function test_cycles_are_rejected(): void
    {
        $a = Category::factory()->create();
        $b = Category::factory()->childOf($a)->create();

        $this->expectException(DomainException::class);
        $a->update(['parent_id' => $b->id]);
    }

    public function test_self_parent_is_rejected(): void
    {
        $a = Category::factory()->create();

        $this->expectException(DomainException::class);
        $a->update(['parent_id' => $a->id]);
    }

    public function test_tree_page_lists_nested_categories(): void
    {
        $root = Category::factory()->create(['name' => 'Networking']);
        Category::factory()->childOf($root)->create(['name' => 'Routers']);

        $this->actingAs(User::factory()->sales()->create());

        Livewire::test(Categories::class)
            ->assertSeeInOrder(['Networking', 'Routers'])
            ->assertSee('aria-level="2"', false);
    }
}
