<?php

namespace Tests\Feature\Api\OneC;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 BR-I.21 / AC-I.28: stale feeds raise a warning on Items and the dashboard; a processed
 * batch clears it; nothing is shown while the inbound API is off.
 */
class StalenessTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function sendAllFeeds(): void
    {
        $this->onec('POST', '/batch', $this->envelope([
            'items' => ['units' => [$this->unitRecord()], 'items' => [$this->itemRecord('00-1')]],
            'stock' => ['as_of' => now()->toIso8601String(), 'warehouses' => [$this->warehouseRecord()], 'stock' => [$this->stockRecord('00-1', '1')]],
        ]))->assertStatus(202);
    }

    public function test_no_warning_while_the_inbound_api_is_off(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('items.index'))->assertOk()->assertDontSee('data-onec-stale', false);
        $this->get(route('dashboard'))->assertOk()->assertDontSee('data-onec-stale', false);
    }

    public function test_stock_older_than_its_threshold_is_flagged_and_a_new_batch_clears_it(): void
    {
        $this->enableInbound();
        $this->issueToken();
        $this->sendAllFeeds();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('items.index'))->assertOk()->assertDontSee('data-onec-stale', false);

        $this->travel(31)->minutes();

        $stockLabel = __('integration.feeds.stock');
        $this->get(route('items.index'))->assertSee('data-onec-stale', false)->assertSee($stockLabel.':')->assertDontSee(__('integration.feeds.items').':');
        $this->get(route('dashboard'))->assertSee('data-onec-stale', false)->assertSee($stockLabel.':');

        $this->onec('POST', '/item-stock', $this->envelope(['as_of' => now()->toIso8601String(), 'stock' => [$this->stockRecord('00-1', '2')]]))->assertStatus(202);

        $this->get(route('items.index'))->assertDontSee('data-onec-stale', false);
    }

    public function test_sales_users_do_not_get_the_dashboard_alert(): void
    {
        $this->enableInbound();

        $this->actingAs(User::factory()->sales()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-onec-stale', false);
    }
}
