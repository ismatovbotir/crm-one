<?php

namespace App\Services\Import;

use App\Models\Unit;
use App\Models\User;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\Inbound\InboundPayload;
use App\Services\OneC\Inbound\ItemUpserter;
use App\Services\OneC\Inbound\ProcessingResult;
use Illuminate\Support\Facades\DB;

/**
 * Go-live item import (roadmap step 7): items are 1C-owned, so a file row is turned into the same
 * record 1C would send and goes through the same validation (BatchValidator) and upsert
 * (ItemUpserter). The 1C GUID column (`guid`) is required, so a later 1C feed updates the same
 * rows instead of creating duplicates; units are matched by code. Columns: guid, code, article,
 * name, type (product|service; "goods" is accepted for product), unit_code, vat_rate (12|0|exempt), sale_price, sale_currency,
 * purchase_price, purchase_currency, prices_include_vat (yes|no), is_serialized (yes|no),
 * accountant_code, class_code, package_code.
 */
class ItemImporter
{
    public const COLUMNS = ['guid', 'code', 'article', 'name', 'type', 'unit_code', 'vat_rate', 'sale_price', 'sale_currency', 'purchase_price', 'purchase_currency', 'prices_include_vat', 'is_serialized', 'accountant_code', 'class_code', 'package_code'];

    public function __construct(protected BatchValidator $validator, protected ItemUpserter $upserter) {}

    /**
     * @param  list<array{row_no: int, values: array<string, string>, formula: bool}>  $rows
     * @return list<array{row_no: int, status: 'ok'|'duplicate'|'error', messages: list<string>, data: array<string, mixed>}>
     */
    public function preview(array $rows): array
    {
        $units = Unit::query()->get(['code', 'external_id'])->mapWithKeys(fn (Unit $unit): array => [mb_strtolower((string) $unit->code) => $unit->external_id])->all();
        $records = [];
        $results = [];

        foreach ($rows as $index => $row) {
            $v = $row['values'];
            $records[$index] = $this->record($v, $units);
            $messages = [];

            if ($row['formula']) {
                $messages[] = __('import.messages.formula');
            }

            if (($v['unit_code'] ?? '') !== '' && ! isset($units[mb_strtolower($v['unit_code'])])) {
                $messages[] = __('import.messages.unit_unknown', ['unit' => $v['unit_code']]);
            }

            $results[$index] = ['row_no' => $row['row_no'], 'status' => $messages === [] ? 'ok' : 'error', 'messages' => $messages, 'data' => $records[$index]];
        }

        foreach ($this->validator->rejectedRecords(new InboundPayload('items', ['items' => array_values($records)])) as $rejected) {
            $index = array_keys($records)[$rejected['index']];
            $results[$index]['status'] = collect($rejected['errors'])->contains(fn (array $error): bool => $error['code'] === 'duplicate_id') ? 'duplicate' : 'error';

            foreach ($rejected['errors'] as $error) {
                $results[$index]['messages'][] = __('import.messages.field_error', ['field' => $error['field'], 'code' => $error['code']]);
            }
        }

        return array_values($results);
    }

    /**
     * Upserts the valid rows like a 1C items batch; returns [created, updated].
     *
     * @param  list<array{row_no: int, status: string, messages: list<string>, data: array<string, mixed>}>  $results
     * @return array{created: int, updated: int, failed: int}
     */
    public function commit(array $results, User $user): array
    {
        $result = new ProcessingResult;

        DB::transaction(function () use ($results, $result): void {
            foreach (array_values(array_filter($results, fn (array $row): bool => $row['status'] === 'ok')) as $index => $row) {
                $this->upserter->upsert($row['data'], $index, $result, null);
            }
        });

        $user->logActivity('items_imported', ['created' => $result->counts['created'], 'updated' => $result->counts['updated'], 'failed' => $result->counts['failed']]);

        return ['created' => $result->counts['created'], 'updated' => $result->counts['updated'], 'failed' => $result->counts['failed']];
    }

    /**
     * @param  array<string, string>  $v
     * @param  array<string, string|null>  $units
     * @return array<string, mixed>
     */
    protected function record(array $v, array $units): array
    {
        $bool = fn (?string $value): ?bool => match (mb_strtolower(trim((string) $value))) {
            'yes', 'ha', 'да', '1', 'true' => true,
            'no', "yo'q", 'нет', '0', 'false' => false,
            default => null,
        };
        $vat = mb_strtolower(trim((string) ($v['vat_rate'] ?? '')));

        $record = [
            'id' => mb_strtolower(trim((string) ($v['guid'] ?? ''))),
            'is_deleted' => false,
            'updated_at' => now()->toIso8601String(),
            'code' => (string) ($v['code'] ?? ''),
            'name' => (string) ($v['name'] ?? ''),
            'type' => match ($type = mb_strtolower(trim((string) ($v['type'] ?? '')))) {
                '', 'goods', 'product', 'tovar', 'товар' => 'product',
                'service', 'xizmat', 'услуга' => 'service',
                default => $type,
            },
            'unit_id' => $units[mb_strtolower((string) ($v['unit_code'] ?? ''))] ?? null,
            'vat_rate' => $vat === '' ? '12' : $vat,
        ];

        foreach (['article', 'accountant_code', 'class_code', 'package_code', 'sale_currency', 'purchase_currency'] as $field) {
            if (($v[$field] ?? '') !== '') {
                $record[$field] = $field === 'sale_currency' || $field === 'purchase_currency' ? strtoupper($v[$field]) : $v[$field];
            }
        }

        foreach (['sale_price', 'purchase_price'] as $field) {
            if (($v[$field] ?? '') !== '') {
                $record[$field] = str_replace([' ', ','], ['', '.'], $v[$field]);
            }
        }

        foreach (['prices_include_vat', 'is_serialized'] as $field) {
            if (($v[$field] ?? '') !== '') {
                $record[$field] = $bool($v[$field]) ?? $v[$field];
            }
        }

        return $record;
    }
}
