<?php

namespace App\Models;

use App\Enums\OneCFeed;
use App\Services\OneC\OneCSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Last received time per 1C feed (Spec 02 section 11.4); drives staleness (BR-I.21).
 * Updated when a batch of the feed is processed, not merely received.
 *
 * @property CarbonImmutable|null $last_received_at
 * @property CarbonImmutable|null $last_full_snapshot_at
 */
#[Fillable(['feed', 'last_received_at', 'last_batch_id', 'last_full_snapshot_at'])]
class IntegrationFeed extends Model
{
    protected $primaryKey = 'feed';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_received_at' => 'immutable_datetime',
            'last_full_snapshot_at' => 'immutable_datetime',
        ];
    }

    /** Customer exchange channels (Spec 02 rev. 3): last contact from 1C per channel. */
    public const CUSTOMERS_POLL = 'customers_poll';

    public const CUSTOMERS_ACK = 'customers_ack';

    public const CUSTOMERS_PUSH = 'customers_push';

    /** Document outbox and payments (Spec 06 section 9). */
    public const OUTBOX_POLL = 'outbox_poll';

    public const OUTBOX_ACK = 'outbox_ack';

    public const PAYMENTS_PUSH = 'payments_push';

    /** Spec 06 26: goods receipts pushed by 1C. */
    public const GOODS_RECEIPTS_PUSH = 'goods_receipts_push';

    public static function touchContact(string $channel): void
    {
        self::query()->updateOrCreate(['feed' => $channel], ['last_received_at' => now()]);
    }

    public static function lastContact(string $channel): ?CarbonImmutable
    {
        return self::query()->find($channel)?->last_received_at;
    }

    public static function markReceived(OneCFeed $feed, string $batchId): void
    {
        self::query()->updateOrCreate(['feed' => $feed->value], ['last_received_at' => now(), 'last_batch_id' => $batchId]);
    }

    public static function markFullSnapshot(OneCFeed $feed): void
    {
        self::query()->updateOrCreate(['feed' => $feed->value], ['last_full_snapshot_at' => now()]);
    }

    /**
     * Status of every feed: last received time, threshold and whether it is stale.
     *
     * @return array<string, array{feed: OneCFeed, last_received_at: CarbonImmutable|null, last_full_snapshot_at: CarbonImmutable|null, stale_minutes: int, is_stale: bool}>
     */
    public static function statuses(): array
    {
        $rows = self::query()->get()->keyBy('feed');
        $settings = app(OneCSettings::class);
        $checking = $settings->inboundEnabled();
        $statuses = [];

        foreach (OneCFeed::cases() as $feed) {
            $row = $rows->get($feed->value);
            $minutes = $settings->staleMinutes($feed);
            $last = $row?->last_received_at;

            $statuses[$feed->value] = [
                'feed' => $feed,
                'last_received_at' => $last,
                'last_full_snapshot_at' => $row?->last_full_snapshot_at,
                'stale_minutes' => $minutes,
                // BR-I.21: only while the inbound API is enabled; never received counts as stale.
                'is_stale' => $checking && ($last === null || $last->lt(now()->subMinutes($minutes))),
            ];
        }

        return $statuses;
    }

    /**
     * @return list<OneCFeed>
     */
    public static function staleFeeds(): array
    {
        return array_values(array_map(
            fn (array $status): OneCFeed => $status['feed'],
            array_filter(self::statuses(), fn (array $status): bool => $status['is_stale']),
        ));
    }
}
