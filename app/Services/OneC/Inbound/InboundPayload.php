<?php

namespace App\Services\OneC\Inbound;

use App\Enums\OneCFeed;

/**
 * Uniform view of a decoded 1C batch body (Spec 02 9.2-9.6) for every endpoint: the single-feed
 * endpoints carry their arrays at the top level, `/batch` nests them under items/stock. Prices
 * travel inside item records (rev. 4: one sale and one purchase price per item).
 */
final class InboundPayload
{
    /** Leaf sections in processing order (Spec 02 7.3) with their feed and top-level parent for /batch. */
    public const SECTIONS = [
        'units' => ['feed' => OneCFeed::Items, 'parent' => 'items'],
        'groups' => ['feed' => OneCFeed::Items, 'parent' => 'items'],
        'items' => ['feed' => OneCFeed::Items, 'parent' => 'items'],
        'warehouses' => ['feed' => OneCFeed::Stock, 'parent' => 'stock'],
        'stock' => ['feed' => OneCFeed::Stock, 'parent' => 'stock'],
    ];

    /** Endpoint => feed for the single-feed endpoints. */
    public const ENDPOINT_FEEDS = [
        'items' => OneCFeed::Items,
        'stock' => OneCFeed::Stock,
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly string $endpoint, public readonly array $data) {}

    /**
     * Feeds this payload carries (single-feed endpoints: their own; /batch: the sections present).
     *
     * @return list<OneCFeed>
     */
    public function feeds(): array
    {
        if (isset(self::ENDPOINT_FEEDS[$this->endpoint])) {
            return [self::ENDPOINT_FEEDS[$this->endpoint]];
        }

        $feeds = [];

        foreach (self::ENDPOINT_FEEDS as $key => $feed) {
            if (array_key_exists($key, $this->data) && is_array($this->data[$key])) {
                $feeds[] = $feed;
            }
        }

        return $feeds;
    }

    /**
     * The container holding the leaf arrays of a feed (top level, or the nested /batch object).
     *
     * @return array<string, mixed>
     */
    public function container(OneCFeed $feed): array
    {
        if ($this->endpoint !== 'batch') {
            return $this->data;
        }

        $nested = $this->data[$feed->value] ?? [];

        return is_array($nested) ? $nested : [];
    }

    /**
     * Records of a leaf section ([] when absent).
     *
     * @return array<int, mixed>
     */
    public function records(string $section): array
    {
        $feed = self::SECTIONS[$section]['feed'];

        if (! in_array($feed, $this->feeds(), true)) {
            return [];
        }

        $records = $this->container($feed)[$section] ?? [];

        return is_array($records) ? $records : [];
    }

    /**
     * Whether a leaf section key was sent (even empty), e.g. to decide snapshot scope.
     */
    public function hasSection(string $section): bool
    {
        $feed = self::SECTIONS[$section]['feed'];

        return in_array($feed, $this->feeds(), true) && array_key_exists($section, $this->container($feed));
    }

    /**
     * Stock `as_of` (top level for /item-stock, inside `stock` for /batch).
     */
    public function asOf(): mixed
    {
        return $this->container(OneCFeed::Stock)['as_of'] ?? null;
    }

    public function recordCount(): int
    {
        $total = 0;

        foreach (array_keys(self::SECTIONS) as $section) {
            $total += count($this->records($section));
        }

        return $total;
    }

    /**
     * @return array<string, int>
     */
    public function receivedCounts(): array
    {
        $counts = [];

        foreach (array_keys(self::SECTIONS) as $section) {
            if ($this->hasSection($section)) {
                $counts[$section] = count($this->records($section));
            }
        }

        return $counts;
    }
}
