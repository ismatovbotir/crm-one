<?php

namespace App\Services\OneC\Inbound;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Shared upsert of a 1C-mastered row identified by `external_id` (Spec 02 7.3, 11.1):
 * out-of-order guard on `updated_at`, hash skip for unchanged content, sync columns,
 * `is_deleted` => inactive + `external_deleted_at`, snapshot "seen" marker.
 */
abstract class CatalogUpserter
{
    /** @var array<int, true> ids seen in the current snapshot without a write */
    protected array $seenIds = [];

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * Map a validated 1C record to the 1C-owned columns of the model.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null null = record failed (reported through $result)
     */
    abstract protected function map(array $record, int $index, ProcessingResult $result): ?array;

    abstract protected function section(): string;

    /**
     * Columns written only when the row is created (CRM-local defaults).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function creationDefaults(array $record, int $index, ProcessingResult $result): array
    {
        return [];
    }

    /**
     * 1C values that are part of the change hash but written later (e.g. a group's parent GUID).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function hashOnly(array $record): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function upsert(array $record, int $index, ProcessingResult $result, ?string $snapshotId): ?Model
    {
        $guid = strtolower((string) $record['id']);
        $mapped = $this->map($record, $index, $result);

        if ($mapped === null) {
            return null;
        }

        $class = $this->modelClass();
        $query = in_array(SoftDeletes::class, class_uses_recursive($class), true) ? $class::withTrashed() : $class::query();
        /** @var Model|null $existing */
        $existing = $query->where('external_id', $guid)->first();
        $updatedAt = BatchValidator::parseDateTime((string) $record['updated_at']);
        $isDeleted = (bool) $record['is_deleted'];
        $hash = $this->hash([...$mapped, ...$this->hashOnly($record), 'is_deleted' => $isDeleted]);

        if ($existing !== null) {
            $storedUpdatedAt = $existing->getAttribute('external_updated_at');

            if ($storedUpdatedAt !== null && $updatedAt !== null && $updatedAt->lt($storedUpdatedAt)) {
                $result->count('stale');
                $this->markSeen($existing, $snapshotId);

                return $existing;
            }

            if ($existing->getAttribute('external_hash') === $hash) {
                $result->count('unchanged');
                $this->markSeen($existing, $snapshotId);

                return $existing;
            }
        }

        $attributes = [
            ...$mapped,
            'is_active' => ! $isDeleted,
            'external_code' => isset($record['code']) ? mb_substr((string) $record['code'], 0, 50) : null,
            'external_version' => $record['version'] ?? null,
            'external_updated_at' => $updatedAt,
            'external_hash' => $hash,
            'synced_at' => now(),
            'external_deleted_at' => $isDeleted ? ($existing?->getAttribute('external_deleted_at') ?? now()) : null,
        ];

        if ($snapshotId !== null) {
            $attributes['last_seen_snapshot_id'] = $snapshotId;
        }

        try {
            $model = DB::transaction(function () use ($existing, $class, $guid, $attributes, $record, $index, $result): Model {
                $model = $existing ?? (new $class)->forceFill(['external_id' => $guid, ...$this->creationDefaults($record, $index, $result)]);
                $model->forceFill($attributes)->save();

                return $model;
            });
        } catch (QueryException $exception) {
            $result->fail($this->section(), $index, $guid, 'conflict', $this->conflictMessage($exception));

            return null;
        } catch (DomainException $exception) {
            $result->fail($this->section(), $index, $guid, 'invalid_tree', $exception->getMessage());

            return null;
        }

        $result->count($existing === null ? 'created' : 'updated');

        return $model;
    }

    /**
     * Rows seen in the snapshot without a write get their marker in one query per section.
     */
    public function flushSeen(?string $snapshotId): void
    {
        if ($snapshotId === null || $this->seenIds === []) {
            $this->seenIds = [];

            return;
        }

        $class = $this->modelClass();

        foreach (array_chunk(array_keys($this->seenIds), 500) as $ids) {
            $class::query()->withoutGlobalScopes()->whereKey($ids)->update(['last_seen_snapshot_id' => $snapshotId]);
        }

        $this->seenIds = [];
    }

    protected function markSeen(Model $model, ?string $snapshotId): void
    {
        if ($snapshotId !== null && $model->getAttribute('last_seen_snapshot_id') !== $snapshotId) {
            $this->seenIds[(int) $model->getKey()] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function hash(array $values): string
    {
        ksort($values);

        return hash('sha256', (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    protected function conflictMessage(QueryException $exception): string
    {
        return str_contains(strtolower($exception->getMessage()), 'unique')
            ? 'code already used by another 1C record'
            : 'database rejected the record';
    }

    public static function parseUtc(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : BatchValidator::parseDateTime($value);
    }
}
