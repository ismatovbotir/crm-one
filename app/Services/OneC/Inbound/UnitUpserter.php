<?php

namespace App\Services\OneC\Inbound;

use App\Models\Unit;

/**
 * Units of measure from 1C. `precision` is CRM-local: seeded from the OKEI map on creation
 * (BR-I.12), unknown codes get 0 and a warning so an admin sets it.
 */
class UnitUpserter extends CatalogUpserter
{
    protected function modelClass(): string
    {
        return Unit::class;
    }

    protected function section(): string
    {
        return 'units';
    }

    protected function map(array $record, int $index, ProcessingResult $result): array
    {
        return [
            'code' => (string) $record['code'],
            'name' => (string) $record['name'],
        ];
    }

    protected function creationDefaults(array $record, int $index, ProcessingResult $result): array
    {
        $map = (array) config('onec.okei_precision');
        $code = (string) $record['code'];

        if (! array_key_exists($code, $map)) {
            $result->warn('units', $index, strtolower((string) $record['id']), 'precision_needed', "unit {$code} is new: set its quantity precision in the CRM");
        }

        return ['precision' => (int) ($map[$code] ?? 0)];
    }
}
