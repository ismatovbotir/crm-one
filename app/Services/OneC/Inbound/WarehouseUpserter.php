<?php

namespace App\Services\OneC\Inbound;

use App\Models\Warehouse;

/**
 * Warehouses from 1C (Spec 02 11.3).
 */
class WarehouseUpserter extends CatalogUpserter
{
    protected function modelClass(): string
    {
        return Warehouse::class;
    }

    protected function section(): string
    {
        return 'warehouses';
    }

    protected function map(array $record, int $index, ProcessingResult $result): array
    {
        return ['name' => (string) $record['name']];
    }
}
