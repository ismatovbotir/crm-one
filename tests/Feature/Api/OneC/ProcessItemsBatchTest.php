<?php

namespace Tests\Feature\Api\OneC;

use App\Enums\InboundBatchStatus;
use App\Enums\ItemType;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\InboundBatch;
use App\Models\IntegrationFeed;
use App\Models\Item;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 7.3, AC-I.16..I.17, I.20..I.21, I.31: items feed processing (runs synchronously in tests).
 */
class ProcessItemsBatchTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
    }

    public function test_units_groups_and_items_are_created_with_references_resolved(): void
    {
        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord('796', 'pcs'), $this->unitRecord('999', 'box')],
            // Child before parent: groups are linked in a second pass.
            'groups' => [$this->groupRecord('brakes', 'auto'), $this->groupRecord('auto')],
            'items' => [$this->itemRecord('00-00001234', ['group_id' => $this->guid('group-brakes'), 'vat_rate' => 'exempt', 'type' => 'service'])],
        ]))->assertStatus(202);

        $item = Item::query()->sole();
        $this->assertSame('00-00001234', $item->sku);
        $this->assertSame('ART-00-00001234', $item->article);
        $this->assertSame($this->guid('item-00-00001234'), $item->external_id);
        $this->assertSame(ItemType::Service, $item->type);
        $this->assertSame(0, $item->tax_rate_bp);
        $this->assertTrue($item->is_vat_exempt);
        $this->assertSame('796', $item->unit->code);
        $this->assertSame('Group brakes', $item->category->name);
        $this->assertSame('Group auto', $item->category->parent->name);
        $this->assertNotNull($item->external_updated_at);

        // BR-I.12: OKEI 796 => 0 decimals; unknown code => 0 and a warning for the admin.
        $this->assertSame(0, Unit::query()->where('code', '999')->value('precision'));
        $batch = InboundBatch::query()->sole();
        $this->assertSame(InboundBatchStatus::Processed, $batch->status);
        $this->assertSame('precision_needed', $batch->errors[0]['code']);
        $this->assertEquals(['created' => 5, 'updated' => 0, 'unchanged' => 0, 'stale' => 0, 'deactivated' => 0, 'failed' => 0, 'rejected' => 0], $batch->counts);

        // Creations are audited by the batch row, not per record.
        $this->assertSame(0, ActivityLog::query()->where('subject_type', 'item')->count());
        $this->assertNotNull(IntegrationFeed::query()->find('items')?->last_received_at);
    }

    public function test_vat_percent_maps_to_basis_points(): void
    {
        $this->seedItemsThroughApi(['00-1']);

        $this->assertSame(1200, Item::query()->sole()->tax_rate_bp);
    }

    public function test_changed_item_is_updated_and_logged_as_synced_from_1c(): void
    {
        $this->seedItemsThroughApi(['00-1']);
        $item = Item::query()->sole();
        $item->forceFill(['min_order_qty' => '5'])->saveQuietly();

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', ['name' => 'Renamed', 'updated_at' => '2026-10-16T10:00:00+05:00'])]]))->assertStatus(202);

        $item->refresh();
        $this->assertSame('Renamed', $item->name);
        $this->assertSame('5.000', (string) $item->min_order_qty, 'CRM-local fields are never overwritten');

        $log = ActivityLog::query()->where('subject_type', 'item')->sole();
        $this->assertSame('synced_from_1c', $log->event);
        $this->assertNull($log->causer_id);
        $this->assertSame(['name' => 'Renamed'], $log->properties['new']);
    }

    public function test_identical_item_is_unchanged_without_activity(): void
    {
        $this->seedItemsThroughApi(['00-1']);
        $before = Item::query()->sole()->updated_at;

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1')]]))->assertStatus(202);

        $this->assertSame(1, InboundBatch::query()->latest('id')->first()->counts['unchanged']);
        $this->assertEquals($before, Item::query()->sole()->updated_at);
        $this->assertSame(0, ActivityLog::query()->where('subject_type', 'item')->count());
    }

    public function test_older_updated_at_is_counted_stale_and_not_applied(): void
    {
        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord()],
            'items' => [$this->itemRecord('00-1', ['name' => 'Newest', 'updated_at' => '2026-10-16T10:05:00+05:00'])],
        ]))->assertStatus(202);

        $this->onec('POST', '/items', $this->envelope([
            'items' => [$this->itemRecord('00-1', ['name' => 'Older', 'updated_at' => '2026-10-16T10:01:00+05:00'])],
        ]))->assertStatus(202);

        $this->assertSame('Newest', Item::query()->sole()->name);
        $this->assertSame(1, InboundBatch::query()->latest('id')->first()->counts['stale']);
    }

    public function test_unknown_unit_fails_that_item_only_and_the_batch_is_partial(): void
    {
        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord()],
            'items' => [$this->itemRecord('00-1'), $this->itemRecord('00-2', ['unit_id' => $this->guid('unit-missing')])],
        ]))->assertStatus(202);

        $batch = InboundBatch::query()->sole();
        $this->assertSame(InboundBatchStatus::Partial, $batch->status);
        $this->assertSame(1, $batch->counts['failed']);
        $this->assertSame(['00-1'], Item::query()->pluck('sku')->all());

        $this->onec('GET', '/batches/'.$batch->batch_id)
            ->assertOk()
            ->assertJsonPath('status', 'partial')
            ->assertJsonPath('errors.0.code', 'unknown_reference')
            ->assertJsonPath('errors.0.id', $this->guid('item-00-2'));
    }

    public function test_deletion_in_a_delta_deactivates_and_keeps_the_row(): void
    {
        $this->seedItemsThroughApi(['00-1']);

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('00-1', ['is_deleted' => true, 'updated_at' => '2026-10-17T10:00:00+05:00'])]]))->assertStatus(202);

        $item = Item::query()->sole();
        $this->assertFalse($item->is_active);
        $this->assertNotNull($item->external_deleted_at);
        $this->assertFalse(Item::query()->sellable()->exists());
        $this->assertSame('deleted_in_1c', ActivityLog::query()->where('subject_type', 'item')->sole()->event);
    }

    public function test_duplicate_skus_from_1c_are_stored_as_separate_items(): void
    {
        $this->onec('POST', '/items', $this->envelope([
            'units' => [$this->unitRecord()],
            'items' => [$this->itemRecord('00-1'), [...$this->itemRecord('00-1-twin'), 'code' => '00-1']],
        ]))->assertStatus(202);

        $this->assertSame(2, Item::query()->where('sku', '00-1')->count());
    }

    public function test_one_c_groups_deeper_than_three_levels_are_mirrored(): void
    {
        $groups = [$this->groupRecord('l1')];

        foreach (range(2, 5) as $level) {
            $groups[] = $this->groupRecord('l'.$level, 'l'.($level - 1));
        }

        $this->onec('POST', '/items', $this->envelope(['groups' => $groups]))->assertStatus(202);

        $this->assertSame(5, Category::query()->where('external_id', $this->guid('group-l5'))->sole()->depth());
    }
}
