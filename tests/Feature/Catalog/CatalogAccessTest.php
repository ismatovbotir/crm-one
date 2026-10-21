<?php

namespace Tests\Feature\Catalog;

use App\Enums\DepartmentRole;
use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 02 section 3 / BR-I.2: the catalog is a read-only mirror. Everybody with a catalog grant may
 * view it; nobody (not even admin) creates, edits or deletes 1C data; only CRM-local fields are editable.
 */
class CatalogAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Closure(): User, array{view: bool, local: bool, precision: bool}}>
     */
    public static function matrix(): array
    {
        return [
            'admin' => [fn () => User::factory()->admin()->create(), ['view' => true, 'local' => true, 'precision' => true]],
            'manager' => [fn () => User::factory()->manager()->create(), ['view' => true, 'local' => true, 'precision' => false]],
            'sales' => [fn () => User::factory()->sales()->create(), ['view' => true, 'local' => false, 'precision' => false]],
            'finance' => [fn () => User::factory()->finance()->create(), ['view' => true, 'local' => false, 'precision' => false]],
            'logistics staff' => [fn () => User::factory()->staff()->inDepartment('logistics')->create(), ['view' => true, 'local' => false, 'precision' => false]],
            'finance head (staff)' => [fn () => User::factory()->staff()->inDepartment('finance', DepartmentRole::Head)->create(), ['view' => true, 'local' => false, 'precision' => false]],
            'staff without department' => [fn () => User::factory()->staff()->create(), ['view' => false, 'local' => false, 'precision' => false]],
        ];
    }

    /**
     * @param  Closure(): User  $makeUser
     * @param  array{view: bool, local: bool, precision: bool}  $expected
     */
    #[DataProvider('matrix')]
    public function test_catalog_permission_matrix(Closure $makeUser, array $expected): void
    {
        $user = $makeUser();
        $item = Item::factory()->create();

        $this->assertSame($expected['view'], $user->can('viewAny', Item::class));
        $this->assertSame($expected['view'], $user->can('view', $item));
        $this->assertSame($expected['view'], $user->can('viewAny', Category::class));
        $this->assertSame($expected['local'], $user->can('updateLocalFields', $item));
        $this->assertFalse($user->can('updatePrecision', $item->unit), 'Units are seed/1C-managed; no CRM editing.');

        // 1C-owned data is never mutable in the CRM.
        $this->assertFalse($user->can('create', Item::class));
        $this->assertFalse($user->can('update', $item));
        $this->assertFalse($user->can('delete', $item));
        $this->assertFalse($user->can('delete', $item->unit));

        $this->actingAs($user);
        $pages = [route('items.index'), route('items.show', $item), route('categories.index')];

        foreach ($pages as $url) {
            $response = $this->get($url);
            $expected['view'] ? $response->assertOk() : $response->assertForbidden();
        }
    }

    public function test_navigation_shows_the_catalog_group(): void
    {
        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertSee('data-nav-group="catalog"', false)
            ->assertSee(route('items.index'), false)
            ->assertSee(route('customers.index'), false);

        $this->actingAs(User::factory()->staff()->create())
            ->get(route('dashboard'))
            ->assertDontSee(route('items.index'), false)
            ->assertDontSee(route('customers.index'), false);
    }

    public function test_items_page_shows_the_1c_badge_and_read_only_note(): void
    {
        Item::factory()->create(['name' => 'Router RX-200', 'synced_at' => now()->subMinutes(5)]);

        $this->actingAs(User::factory()->sales()->create())
            ->get(route('items.index'))
            ->assertOk()
            ->assertSee(__('integration.badge.synced_from_1c'))
            ->assertSee(__('integration.last_received'))
            ->assertSee(__('integration.read_only'))
            ->assertSee('Router RX-200');
    }

    public function test_units_page_is_gone_from_routes_and_navigation(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->get('/catalog/units')->assertNotFound();
        $this->get(route('dashboard'))->assertDontSee('/catalog/units', false);
        $this->assertFalse(Route::has('units.index'));
    }
}
