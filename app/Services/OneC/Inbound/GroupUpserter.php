<?php

namespace App\Services\OneC\Inbound;

use App\Models\Category;
use DomainException;

/**
 * Item groups from 1C (categories). Two passes (Spec 02 7.3): upsert every group, then link
 * `parent_id` by GUID so parents may arrive after their children. 1C groups keep any depth;
 * cycles are refused. `sort_order` is CRM-local.
 */
class GroupUpserter extends CatalogUpserter
{
    protected function modelClass(): string
    {
        return Category::class;
    }

    protected function section(): string
    {
        return 'groups';
    }

    protected function map(array $record, int $index, ProcessingResult $result): array
    {
        return ['name' => (string) $record['name']];
    }

    protected function hashOnly(array $record): array
    {
        return ['parent_id' => isset($record['parent_id']) ? strtolower((string) $record['parent_id']) : null];
    }

    /**
     * Second pass: point each group at its parent (null when the parent GUID is unknown, with a warning).
     *
     * @param  array<string, mixed>  $record
     */
    public function linkParent(Category $category, array $record, int $index, ProcessingResult $result): void
    {
        $parentGuid = isset($record['parent_id']) ? strtolower((string) $record['parent_id']) : null;
        $parentId = $parentGuid === null ? null : Category::query()->where('external_id', $parentGuid)->value('id');

        if ($parentGuid !== null && $parentId === null) {
            $result->warn('groups', $index, $category->external_id, 'unknown_reference', "parent group {$parentGuid} not found; placed at the top level");
        }

        if ($category->parent_id === $parentId) {
            return;
        }

        try {
            $category->forceFill(['parent_id' => $parentId])->save();
        } catch (DomainException $exception) {
            $result->warn('groups', $index, $category->external_id, 'invalid_tree', $exception->getMessage());
        }
    }
}
