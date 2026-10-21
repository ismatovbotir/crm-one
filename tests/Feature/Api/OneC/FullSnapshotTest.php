<?php

namespace Tests\Feature\Api\OneC;

use App\Models\InboundSnapshot;
use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithOneCApi;
use Tests\TestCase;

/**
 * Spec 02 7.4, AC-I.22..I.25: delta vs full snapshot, multi-part finalization, truncation guard,
 * TTL expiry and snapshot mismatch.
 */
class FullSnapshotTest extends TestCase
{
    use InteractsWithOneCApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableInbound();
        $this->issueToken();
    }

    /**
     * @param  list<string>  $codes
     */
    protected function sendPart(string $snapshotKey, int $part, int $total, array $codes): void
    {
        $this->onec('POST', '/items', $this->envelope(
            ['items' => array_map(fn (string $code) => $this->itemRecord($code), $codes)],
            'full',
            ['id' => $this->guid($snapshotKey), 'part' => $part, 'total_parts' => $total],
        ))->assertStatus(202);
    }

    public function test_a_delta_never_deactivates_rows_it_does_not_mention(): void
    {
        $this->seedItemsThroughApi(['A', 'B', 'C']);

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('A')]]))->assertStatus(202);

        $this->assertSame(3, Item::query()->where('is_active', true)->count());
    }

    public function test_missing_item_is_deactivated_only_after_the_last_part(): void
    {
        $codes = array_map(fn (int $i) => 'I'.$i, range(1, 10));
        $this->seedItemsThroughApi($codes);

        $this->sendPart('snap-1', 1, 3, ['I1', 'I2', 'I3']);
        $this->sendPart('snap-1', 2, 3, ['I4', 'I5', 'I6']); // I10 never sent

        $this->assertTrue(Item::query()->where('sku', 'I10')->sole()->is_active, 'No deactivation before the last part');

        $this->sendPart('snap-1', 3, 3, ['I7', 'I8', 'I9']);

        $missing = Item::query()->where('sku', 'I10')->sole();
        $this->assertFalse($missing->is_active);
        $this->assertNotNull($missing->external_deleted_at);
        $this->assertSame(9, Item::query()->where('is_active', true)->count());
        $this->assertSame(InboundSnapshot::FINALIZED, InboundSnapshot::query()->sole()->status);
    }

    public function test_parts_may_arrive_out_of_order(): void
    {
        $this->seedItemsThroughApi(['A', 'B', 'C']);

        $this->sendPart('snap-ooo', 2, 2, ['B', 'C']);
        $this->sendPart('snap-ooo', 1, 2, ['A']);

        $this->assertSame(3, Item::query()->where('is_active', true)->count());
        $this->assertSame(InboundSnapshot::FINALIZED, InboundSnapshot::query()->sole()->status);
    }

    public function test_truncated_snapshot_trips_the_guard_and_deactivates_nothing(): void
    {
        $this->seedItemsThroughApi(['A', 'B', 'C', 'D']);

        $this->sendPart('snap-small', 1, 1, ['A', 'B']); // 50% < 80% guard

        $this->assertSame(4, Item::query()->where('is_active', true)->count());
        $snapshot = InboundSnapshot::query()->sole();
        $this->assertSame(InboundSnapshot::PARTIAL, $snapshot->status);
        $this->assertSame(['seen' => 2, 'active' => 4], $snapshot->result['guard_tripped']['items']);
    }

    public function test_snapshot_past_its_ttl_expires_without_deactivation(): void
    {
        $this->seedItemsThroughApi(['A', 'B', 'C']);

        $this->sendPart('snap-ttl', 1, 2, ['A', 'B']);
        $this->travel(121)->minutes();
        $this->sendPart('snap-ttl', 2, 2, []);

        $this->assertSame(InboundSnapshot::EXPIRED, InboundSnapshot::query()->sole()->status);
        $this->assertSame(3, Item::query()->where('is_active', true)->count());
    }

    public function test_conflicting_total_parts_is_409(): void
    {
        $this->seedItemsThroughApi(['A']);
        $this->sendPart('snap-x', 1, 3, ['A']);

        $this->onec('POST', '/items', $this->envelope(['items' => [$this->itemRecord('A')]], 'full', ['id' => $this->guid('snap-x'), 'part' => 2, 'total_parts' => 4]))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'snapshot_mismatch');
    }
}
