<?php

namespace Tests\Feature;

use App\Actions\Orders\ConfirmOrder;
use App\Enums\DepartmentRole;
use App\Models\Lead;
use App\Models\User;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/**
 * Roadmap step 2: role-based dashboards with real figures; sections follow the permissions.
 */
class DashboardRolesTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    /**
     * @return array<string, array<string, string>>
     */
    private function tiles(User $user): array
    {
        return collect(app(DashboardMetrics::class)->sections($user))
            ->mapWithKeys(fn (array $section): array => [$section['key'] => collect($section['tiles'])->pluck('value', 'label')->all()])
            ->all();
    }

    public function test_sales_sees_own_leads_and_funnel(): void
    {
        $rep = User::factory()->sales()->create();
        Lead::factory()->ownedBy($rep)->create();
        Lead::factory()->ownedBy($rep)->create(['last_activity_at' => now()->subDays(30)]);
        Lead::factory()->create();

        $tiles = $this->tiles($rep);
        $this->assertArrayHasKey('sales', $tiles);
        $this->assertArrayNotHasKey('accounting', $tiles);
        $this->assertSame('2', $tiles['sales'][__('dashboard.tiles.my_open_leads')]);
        $this->assertStringContainsString(__('leads.status.new').' 2', $tiles['sales'][__('dashboard.tiles.funnel')], 'Only visible leads count.');

        $this->actingAs($rep)->get(route('dashboard'))->assertOk()->assertSee('data-dashboard-section="sales"', false)->assertSee(__('dashboard.tiles.stale', ['count' => 1]));
    }

    public function test_accountant_sees_receivables_and_unpaid_orders(): void
    {
        $rep = User::factory()->sales()->create();
        $order = $this->simpleOrder($rep, '2');
        app(ConfirmOrder::class)->handle($order, $rep, true);
        $accountant = User::factory()->accountant()->create();

        $tiles = $this->tiles($accountant);
        $this->assertArrayHasKey('accounting', $tiles);
        $this->assertArrayNotHasKey('sales', $tiles);
        $this->assertSame('1', $tiles['accounting'][__('dashboard.tiles.unpaid_orders')]);
        $this->assertSame(Money::of($order->fresh()->gross_minor, 'UZS')->format(), $tiles['accounting'][__('dashboard.tiles.receivable')]);
    }

    public function test_warehouse_logistics_and_approvers(): void
    {
        $rep = User::factory()->sales()->create();
        app(ConfirmOrder::class)->handle($this->simpleOrder($rep, '1'), $rep, true);

        $stock = $this->tiles(User::factory()->stockManager()->create());
        $this->assertArrayHasKey('warehouse', $stock);
        $this->assertSame('1', $stock['warehouse'][__('dashboard.tiles.to_pack')]);

        $logistics = $this->tiles(User::factory()->staff()->inDepartment('logistics', DepartmentRole::Head)->create());
        $this->assertArrayHasKey('procurement', $logistics);

        $manager = $this->tiles(User::factory()->manager()->create());
        $this->assertArrayHasKey('approvals', $manager);
        $this->assertSame('0', $manager['approvals'][__('dashboard.tiles.offer_approvals')]);
    }
}
