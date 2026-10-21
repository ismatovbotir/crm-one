<?php

namespace App\Services\OneC\Inbound;

use App\Enums\OneCFeed;
use App\Models\Category;
use App\Models\InboundSnapshot;
use App\Models\IntegrationFeed;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * After the last part of a full snapshot (Spec 02 7.4): rows of the feed's scope that were not
 * seen in any part are deactivated (catalog) or removed (stock), unless
 * - the snapshot is past its TTL (status `expired`, nothing removed), or
 * - fewer than the guard percentage of the active rows were seen (status `partial`, nothing removed).
 *
 * Items feed: items always; units and groups only if the snapshot carried that section.
 * Stock feed: per warehouse in scope.warehouse_ids (default: warehouses present in the snapshot).
 */
class SnapshotFinalizer
{
    public const CHUNK = 500;

    /**
     * @return int rows deactivated or removed
     */
    public function finalize(InboundSnapshot $snapshot): int
    {
        if ($snapshot->isPastTtl()) {
            $snapshot->forceFill(['status' => InboundSnapshot::EXPIRED, 'finalized_at' => now()])->save();

            return 0;
        }

        $seen = $snapshot->result ?? [];
        $targets = $this->targets($snapshot, $seen);
        $guard = (int) config('onec.inbound.full_snapshot_guard_percent');
        $tripped = [];

        foreach ($targets as $name => $target) {
            $active = (clone $target['active'])->count();
            $seenCount = (clone $target['active'])->where($target['seen_column'], $snapshot->id)->count();

            if ($active > 0 && $seenCount * 100 < $guard * $active) {
                $tripped[$name] = ['seen' => $seenCount, 'active' => $active];
            }
        }

        if ($tripped !== []) {
            $snapshot->forceFill([
                'status' => InboundSnapshot::PARTIAL,
                'finalized_at' => now(),
                'result' => [...$seen, 'guard_tripped' => $tripped],
            ])->save();

            return 0;
        }

        // DB review F-01: bounded chunks, each in its own short transaction (no huge result set or
        // transaction). Stock rows are deleted set-based (no per-row audit); catalog rows keep the
        // per-row `deleted_in_1c` audit through their model.
        $removed = OneCSyncContext::run(function () use ($targets, $snapshot): int {
            $total = 0;

            foreach ($targets as $target) {
                do {
                    $ids = (clone $target['active'])
                        ->where(fn (Builder $query) => $query->whereNull($target['seen_column'])->orWhere($target['seen_column'], '!=', $snapshot->id))
                        ->orderBy($target['active']->getModel()->getKeyName())
                        ->limit(self::CHUNK)
                        ->pluck($target['active']->getModel()->getKeyName());

                    if ($ids->isEmpty()) {
                        break;
                    }

                    $total += DB::transaction(function () use ($target, $ids): int {
                        if ($target['set_delete'] ?? false) {
                            return $target['active']->getModel()->newQuery()->whereKey($ids)->delete();
                        }

                        $models = $target['active']->getModel()->newQuery()->whereKey($ids)->get();
                        $models->each(fn (Model $model) => $target['remove']($model));

                        return $models->count();
                    });
                } while ($ids->count() === self::CHUNK);
            }

            return $total;
        });

        foreach ($seen['feeds'] ?? [] as $feed) {
            if ($feed = OneCFeed::tryFrom($feed)) {
                IntegrationFeed::markFullSnapshot($feed);
            }
        }

        $snapshot->forceFill([
            'status' => InboundSnapshot::FINALIZED,
            'finalized_at' => now(),
            'result' => [...$seen, 'removed' => $removed],
        ])->save();

        return $removed;
    }

    /**
     * Query of active rows in scope, the "seen" column and how to remove a row, per target.
     *
     * @param  array<string, mixed>  $seen
     * @return array<string, array{active: Builder<covariant Model>, seen_column: string, remove: callable(Model): void, set_delete?: bool}>
     */
    protected function targets(InboundSnapshot $snapshot, array $seen): array
    {
        $feeds = $seen['feeds'] ?? [];
        $sections = $seen['sections'] ?? [];
        $deactivate = function (Model $model): void {
            $model->forceFill(['is_active' => false, 'external_deleted_at' => now()])->save();
        };
        $activeCatalog = fn (string $class): Builder => $class::query()->where('is_active', true)->whereNull('external_deleted_at');
        $targets = [];

        if (in_array(OneCFeed::Items->value, $feeds, true)) {
            $targets['items'] = ['active' => $activeCatalog(Item::class), 'seen_column' => 'last_seen_snapshot_id', 'remove' => $deactivate];

            if (in_array('units', $sections, true)) {
                $targets['units'] = ['active' => $activeCatalog(Unit::class), 'seen_column' => 'last_seen_snapshot_id', 'remove' => $deactivate];
            }

            if (in_array('groups', $sections, true)) {
                $targets['groups'] = ['active' => $activeCatalog(Category::class)->whereNotNull('external_id'), 'seen_column' => 'last_seen_snapshot_id', 'remove' => $deactivate];
            }
        }

        if (in_array(OneCFeed::Stock->value, $feeds, true)) {
            $warehouseGuids = $snapshot->scope['warehouse_ids'] ?? $seen['warehouse_ids'] ?? [];
            $warehouseIds = Warehouse::query()->whereIn('external_id', array_map('strtolower', $warehouseGuids))->pluck('id')->all();

            if ($warehouseIds !== []) {
                $targets['stock'] = [
                    'active' => ItemStock::query()->whereIn('warehouse_id', $warehouseIds),
                    'seen_column' => 'last_seen_snapshot_id',
                    'remove' => fn (Model $row) => $row->delete(),
                    'set_delete' => true,
                ];
            }
        }

        return $targets;
    }

    /**
     * Expires snapshots still collecting after the TTL (scheduled): no deactivation.
     */
    public function expireStale(): int
    {
        $expired = 0;

        InboundSnapshot::query()
            ->where('status', InboundSnapshot::COLLECTING)
            ->where('started_at', '<', now()->subMinutes((int) config('onec.inbound.snapshot_ttl_minutes')))
            ->get()
            ->each(function (InboundSnapshot $snapshot) use (&$expired): void {
                $snapshot->forceFill(['status' => InboundSnapshot::EXPIRED, 'finalized_at' => now()])->save();
                $expired++;
            });

        return $expired;
    }
}
