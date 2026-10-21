<?php

namespace Tests\Feature\Api\OneC;

use App\Enums\InboundBatchStatus;
use App\Jobs\OneC\ProcessInboundBatch;
use App\Models\InboundBatch;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 7.2, AC-I.10..I.13: envelope validation, size limits, media type, per-record
 * rejections (207) and the all-invalid case (422).
 */
class IntakeValidationTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
    }

    public function test_valid_batch_is_accepted_with_202_and_queued(): void
    {
        Queue::fake();
        $body = $this->envelope(['units' => [$this->unitRecord()], 'items' => [$this->itemRecord('00-001')]]);

        $this->onec('POST', '/items', $body)
            ->assertStatus(202)
            ->assertJsonPath('batch_id', $body['batch_id'])
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('received', ['units' => 1, 'items' => 1])
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('rejected', [])
            ->assertJsonPath('status_url', '/api/v1/integration/1c/batches/'.$body['batch_id']);

        $batch = InboundBatch::query()->sole();
        $this->assertSame(InboundBatchStatus::Queued, $batch->status);
        $this->assertSame(hash('sha256', json_encode($body)), $batch->payload_hash);
        Queue::assertPushedOn('onec-inbound', ProcessInboundBatch::class);
    }

    public function test_missing_batch_id_is_422_and_nothing_is_stored(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()]]);
        unset($body['batch_id']);

        $this->onec('POST', '/items', $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields.batch_id', ['required']);

        $this->assertSame(0, InboundBatch::query()->count());
    }

    public function test_invalid_envelope_with_a_batch_id_is_stored_as_rejected(): void
    {
        $body = $this->envelope(['units' => [$this->unitRecord()]], mode: 'sometimes');

        $this->onec('POST', '/items', $body)->assertStatus(422)->assertJsonPath('error.fields.mode', ['invalid_enum']);

        $this->assertSame(InboundBatchStatus::Rejected, InboundBatch::query()->sole()->status);
    }

    public function test_full_mode_requires_a_snapshot_block(): void
    {
        $this->onec('POST', '/items', $this->envelope(['units' => [$this->unitRecord()]], mode: 'full'))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.snapshot', ['required']);
    }

    public function test_stock_requires_as_of(): void
    {
        $this->onec('POST', '/item-stock', $this->envelope(['warehouses' => [$this->warehouseRecord()]]))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.as_of', ['required']);
    }

    public function test_more_than_the_record_limit_is_refused(): void
    {
        config(['onec.inbound.max_records' => 1000]);
        $units = array_map(fn (int $i) => $this->unitRecord('U'.$i), range(1, 1001));

        $this->onec('POST', '/items', $this->envelope(['units' => $units]))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.records', ['too_many_records']);

        $this->assertSame(0, Item::query()->count());
    }

    public function test_oversized_body_is_413_before_parsing(): void
    {
        config(['onec.inbound.max_body_kb' => 1]);

        $this->onec('POST', '/items', $this->envelope(['units' => array_map(fn (int $i) => $this->unitRecord('U'.$i), range(1, 40))]))
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'payload_too_large');
    }

    public function test_non_json_content_type_is_415(): void
    {
        $this->call('POST', '/api/v1/integration/1c/items', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainToken], 'hello')
            ->assertStatus(415)
            ->assertJsonPath('error.code', 'unsupported_media_type');
    }

    public function test_invalid_records_are_listed_with_207_and_the_valid_ones_are_processed(): void
    {
        $items = array_map(fn (int $i) => $this->itemRecord('00-'.$i), range(1, 10));
        unset($items[2]['unit_id']);
        $items[5]['type'] = 'bundle';
        $items[7]['vat_rate'] = 12;

        $response = $this->onec('POST', '/items', $this->envelope(['units' => [$this->unitRecord()], 'items' => $items]))
            ->assertStatus(207)
            ->assertJsonPath('accepted', 8)
            ->assertJsonCount(3, 'rejected');

        $response->assertJsonPath('rejected.0', [
            'section' => 'items', 'index' => 2, 'id' => $items[2]['id'],
            'errors' => [['field' => 'unit_id', 'code' => 'required', 'message' => 'unit_id is required']],
        ]);
        $response->assertJsonPath('rejected.1.errors.0.code', 'invalid_enum');
        $response->assertJsonPath('rejected.2.errors.0.code', 'must_be_string');

        $this->assertSame(7, Item::query()->count());
        $this->assertSame(InboundBatchStatus::Partial, InboundBatch::query()->sole()->status);
    }

    public function test_money_as_json_number_and_negative_prices_are_rejected(): void
    {
        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord()],
            'items' => [
                $this->itemRecord('00-1', ['sale_price' => 125000.5]),
                $this->itemRecord('00-2', ['purchase_price' => '-5.00']),
                $this->itemRecord('00-3', ['sale_price' => '10.00', 'sale_currency' => 'XXX']),
                $this->itemRecord('00-4', ['sale_price' => '10.00']),
            ],
        ]))
            ->assertStatus(207)
            ->assertJsonPath('rejected.0.errors.0.code', 'must_be_decimal_string')
            ->assertJsonPath('rejected.1.errors.0.code', 'negative')
            ->assertJsonPath('rejected.2.errors.0.code', 'invalid_currency')
            ->assertJsonCount(3, 'rejected');
    }

    public function test_duplicate_records_in_one_batch_are_rejected(): void
    {
        $this->onec('POST', '/items', $this->envelope(['units' => [$this->unitRecord(), $this->unitRecord()]]))
            ->assertStatus(207)
            ->assertJsonPath('rejected.0.index', 1)
            ->assertJsonPath('rejected.0.errors.0.code', 'duplicate_id');
    }

    public function test_all_invalid_records_give_422_and_a_rejected_batch(): void
    {
        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', ['unit_id' => 'not-a-guid'])]]))
            ->assertStatus(422)
            ->assertJsonPath('error.fields.records', ['all_invalid'])
            ->assertJsonPath('error.rejected.0.errors.0.code', 'invalid_guid');

        $this->assertSame(InboundBatchStatus::Rejected, InboundBatch::query()->sole()->status);
    }

    public function test_a_utf8_bom_is_stripped(): void
    {
        $raw = "\xEF\xBB\xBF".json_encode($this->envelope(['units' => [$this->unitRecord()]]));

        $this->call('POST', '/api/v1/integration/1c/items', [], [], [], ['CONTENT_TYPE' => 'application/json; charset=utf-8', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->plainToken], $raw)
            ->assertStatus(202);
    }
}
