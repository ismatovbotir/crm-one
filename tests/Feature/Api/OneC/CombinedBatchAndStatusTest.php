<?php

namespace Tests\Feature\Api\OneC;

use App\Enums\IntegrationAbility;
use App\Models\InboundBatch;
use App\Models\Item;
use App\Models\ItemStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 AC-I.27 (combined batch in fixed order) and GET /batches/{id} with token scoping.
 */
class CombinedBatchAndStatusTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
    }

    public function test_combined_batch_creates_the_item_with_its_prices_before_its_stock(): void
    {
        $body = $this->envelope([
            'stock' => ['as_of' => '2026-10-16T14:10:00+05:00', 'warehouses' => [$this->warehouseRecord()], 'stock' => [[...$this->stockRecord('NEW-1', '4.000'), 'cost' => '200.00']]],
            'items' => ['units' => [$this->unitRecord()], 'items' => [$this->itemRecord('NEW-1', ['sale_price' => '99.90', 'purchase_price' => '45.00'])]],
        ]);

        $this->onec('POST', '/batch', $body)
            ->assertStatus(202)
            ->assertJsonPath('received', ['units' => 1, 'items' => 1, 'warehouses' => 1, 'stock' => 1]);

        $item = Item::query()->sole();
        $this->assertSame([9990, 'UZS', 4500], [$item->sale_price_minor, $item->sale_currency, $item->purchase_price_minor]);
        $this->assertSame(5000, $item->stockCostPrice()?->amount, 'Stock cost 200.00 / 4 = 50.00 per unit.');
        $this->assertSame('4.000', (string) ItemStock::query()->where('item_id', $item->id)->value('quantity'));
        $this->assertSame(0, InboundBatch::query()->sole()->counts['failed']);
    }

    public function test_batch_status_is_visible_to_the_sending_token(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        $this->onec('POST', '/items', $body)->assertStatus(202);

        $this->onec('GET', '/batches/'.$body['batch_id'])
            ->assertOk()
            ->assertJsonPath('batch_id', $body['batch_id'])
            ->assertJsonPath('status', 'processed')
            ->assertJsonPath('mode', 'delta')
            ->assertJsonPath('counts.created', 1)
            ->assertJsonPath('snapshot', null);
    }

    public function test_another_tokens_batch_is_404(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        $this->onec('POST', '/items', $body)->assertStatus(202);

        $this->issueToken();

        $this->onec('GET', '/batches/'.$body['batch_id'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->onec('GET', '/batches/not-a-uuid')->assertNotFound();
    }

    public function test_batch_status_needs_the_batches_read_ability(): void
    {
        $this->issueToken([IntegrationAbility::ItemsWrite->value]);

        $this->onec('GET', '/batches/'.$this->guid('anything'))->assertForbidden()->assertJsonPath('error.code', 'forbidden_ability');
    }

    public function test_unknown_api_routes_use_the_error_envelope(): void
    {
        $this->onec('GET', '/nope')->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }
}
