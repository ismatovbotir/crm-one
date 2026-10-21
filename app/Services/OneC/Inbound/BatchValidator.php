<?php

namespace App\Services\OneC\Inbound;

use App\Enums\ItemType;
use App\Enums\OneCFeed;
use App\Enums\SyncMode;
use App\Support\ItemCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Intake validation of 1C batches (Spec 02 7.2): envelope errors fail the whole request (422);
 * record errors are collected per record (`rejected[]`) while valid records continue.
 */
class BatchValidator
{
    public const GUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public const DATETIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/';

    public const MONEY_PATTERN = '/^\d{1,15}(\.\d{1,6})?$/';

    public const QUANTITY_PATTERN = '/^-?\d{1,12}(\.\d{1,3})?$/';

    /**
     * Envelope errors as field => list of codes ([] = valid).
     *
     * @return array<string, list<string>>
     */
    public function envelopeErrors(InboundPayload $payload): array
    {
        $data = $payload->data;
        $errors = [];

        if (! isset($data['batch_id']) || ! is_string($data['batch_id']) || preg_match(self::GUID_PATTERN, $data['batch_id']) !== 1) {
            $errors['batch_id'][] = isset($data['batch_id']) ? 'invalid_guid' : 'required';
        }

        $mode = is_string($data['mode'] ?? null) ? SyncMode::tryFrom($data['mode']) : null;

        if ($mode === null) {
            $errors['mode'][] = isset($data['mode']) ? 'invalid_enum' : 'required';
        }

        if (! isset($data['sent_at']) || ! $this->isDateTime($data['sent_at'])) {
            $errors['sent_at'][] = isset($data['sent_at']) ? 'invalid_datetime' : 'required';
        }

        if (isset($data['source']) && ! is_array($data['source'])) {
            $errors['source'][] = 'invalid_type';
        }

        if ($mode === SyncMode::Full) {
            $errors = [...$errors, ...$this->snapshotErrors($data['snapshot'] ?? null)];
        }

        if ($payload->endpoint === 'batch' && $payload->feeds() === []) {
            $errors['items'][] = 'required';
        }

        foreach ($payload->feeds() as $feed) {
            $container = $payload->container($feed);
            $prefix = $payload->endpoint === 'batch' ? $feed->value.'.' : '';

            foreach (InboundPayload::SECTIONS as $section => $meta) {
                if ($meta['feed'] !== $feed || ! array_key_exists($section, $container)) {
                    continue;
                }

                if (! is_array($container[$section]) || ! array_is_list($container[$section])) {
                    $errors[$prefix.$section][] = 'must_be_array';
                }
            }

            if ($feed === OneCFeed::Stock && ! $this->isDateTime($container['as_of'] ?? null)) {
                $errors[$prefix.'as_of'][] = isset($container['as_of']) ? 'invalid_datetime' : 'required';
            }
        }

        if ($errors === [] && $payload->recordCount() > (int) config('onec.inbound.max_records')) {
            $errors['records'][] = 'too_many_records';
        }

        return $errors;
    }

    /**
     * @return array<string, list<string>>
     */
    protected function snapshotErrors(mixed $snapshot): array
    {
        if (! is_array($snapshot)) {
            return ['snapshot' => ['required']];
        }

        $errors = [];

        if (! is_string($snapshot['id'] ?? null) || preg_match(self::GUID_PATTERN, $snapshot['id']) !== 1) {
            $errors['snapshot.id'][] = 'invalid_guid';
        }

        $part = $snapshot['part'] ?? null;
        $total = $snapshot['total_parts'] ?? null;

        if (! is_int($total) || $total < 1 || $total > 10000) {
            $errors['snapshot.total_parts'][] = 'invalid_integer';
        }

        if (! is_int($part) || $part < 1 || (is_int($total) && $part > $total)) {
            $errors['snapshot.part'][] = 'invalid_integer';
        }

        foreach (['warehouse_ids'] as $key) {
            $ids = $snapshot['scope'][$key] ?? null;

            if ($ids === null) {
                continue;
            }

            if (! is_array($ids) || ! array_is_list($ids) || array_filter($ids, fn (mixed $id): bool => ! is_string($id) || preg_match(self::GUID_PATTERN, $id) !== 1) !== []) {
                $errors['snapshot.scope.'.$key][] = 'invalid_guid';
            }
        }

        return $errors;
    }

    /**
     * Per-record validation of every section: rejected entries `{section, index, id, errors[]}`.
     *
     * @return list<array{section: string, index: int, id: string|null, errors: list<array{field: string, code: string, message: string}>}>
     */
    public function rejectedRecords(InboundPayload $payload): array
    {
        $rejected = [];

        foreach (array_keys(InboundPayload::SECTIONS) as $section) {
            $seen = [];

            foreach ($payload->records($section) as $index => $record) {
                $errors = is_array($record) && ! array_is_list($record)
                    ? $this->recordErrors($section, $record)
                    : [$this->error('record', 'invalid_type')];

                $key = is_array($record) ? $this->recordKey($section, $record) : null;

                if ($errors === [] && $key !== null) {
                    if (isset($seen[$key])) {
                        $errors[] = $this->error('id', 'duplicate_id');
                    }

                    $seen[$key] = true;
                }

                if ($errors !== []) {
                    $rejected[] = [
                        'section' => $section,
                        'index' => $index,
                        'id' => is_array($record) && is_string($record['id'] ?? null) ? $record['id'] : null,
                        'errors' => $errors,
                    ];
                }
            }
        }

        return $rejected;
    }

    /**
     * Identity of a record inside one batch (duplicates are rejected).
     *
     * @param  array<string, mixed>  $record
     */
    public function recordKey(string $section, array $record): ?string
    {
        return match ($section) {
            'stock' => strtolower((string) ($record['item_id'] ?? '')).'|'.strtolower((string) ($record['warehouse_id'] ?? '')),
            default => is_string($record['id'] ?? null) ? strtolower($record['id']) : null,
        };
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{field: string, code: string, message: string}>
     */
    protected function recordErrors(string $section, array $record): array
    {
        $errors = [];
        $check = function (string $field, ?string $code) use (&$errors): void {
            if ($code !== null) {
                $errors[] = $this->error($field, $code);
            }
        };

        $common = fn () => [
            $check('id', $this->guid($record, 'id', required: true)),
            $check('is_deleted', $this->boolean($record, 'is_deleted')),
            $check('updated_at', $this->dateTime($record, 'updated_at')),
            $check('version', $this->string($record, 'version', 64, required: false)),
        ];

        switch ($section) {
            case 'units':
                $common();
                $check('code', $this->string($record, 'code', 16));
                $check('name', $this->string($record, 'name', 64));
                break;

            case 'groups':
                $common();
                $check('code', $this->string($record, 'code', 50, required: false));
                $check('name', $this->string($record, 'name', 120));
                $check('parent_id', $this->guid($record, 'parent_id', required: false));
                break;

            case 'items':
                $common();
                $check('code', $this->string($record, 'code', 64));
                $check('article', $this->string($record, 'article', 64, required: false));
                $check('name', $this->string($record, 'name', 255));
                $check('description', $this->string($record, 'description', 65000, required: false));
                $check('type', is_string($record['type'] ?? null) ? (ItemType::tryFrom($record['type']) ? null : 'invalid_enum') : 'required');
                $check('group_id', $this->guid($record, 'group_id', required: false));
                $check('unit_id', $this->guid($record, 'unit_id', required: true));
                $check('vat_rate', $this->vatRate($record['vat_rate'] ?? null));
                $check('accountant_code', $this->itemCode($record, 'accountant_code', ItemCodes::accountantCodeError(...)));
                $check('class_code', $this->itemCode($record, 'class_code', ItemCodes::classCodeError(...)));
                $check('package_code', $this->itemCode($record, 'package_code', ItemCodes::packageCodeError(...)));
                $check('is_serialized', array_key_exists('is_serialized', $record) && $record['is_serialized'] !== null && ! is_bool($record['is_serialized']) ? 'must_be_boolean' : null);
                $check('sale_price', $this->optionalMoney($record, 'sale_price'));
                $check('purchase_price', $this->optionalMoney($record, 'purchase_price'));
                $check('sale_currency', $this->optionalCurrency($record, 'sale_currency'));
                $check('purchase_currency', $this->optionalCurrency($record, 'purchase_currency'));
                $check('prices_include_vat', array_key_exists('prices_include_vat', $record) && $record['prices_include_vat'] !== null && ! is_bool($record['prices_include_vat']) ? 'must_be_boolean' : null);
                break;

            case 'warehouses':
                $common();
                $check('code', $this->string($record, 'code', 50, required: false));
                $check('name', $this->string($record, 'name', 255));
                break;

            case 'stock':
                $check('item_id', $this->guid($record, 'item_id', required: true));
                $check('warehouse_id', $this->guid($record, 'warehouse_id', required: true));
                $check('quantity', $this->quantity($record, 'quantity', required: true));
                $check('reserved', $this->quantity($record, 'reserved', required: false, allowNegative: false));
                $check('cost', $this->optionalMoney($record, 'cost', allowNegative: true));
                $check('unit_cost', $this->optionalMoney($record, 'unit_cost'));
                $check('cost_currency', $this->optionalCurrency($record, 'cost_currency'));
                break;
        }

        return $errors;
    }

    public function isDateTime(mixed $value): bool
    {
        return is_string($value) && preg_match(self::DATETIME_PATTERN, $value) === 1 && self::parseDateTime($value) !== null;
    }

    /**
     * ISO 8601 datetime to UTC; a value without offset is read in onec.timezone (BR-I.5).
     */
    public static function parseDateTime(string $value): ?CarbonImmutable
    {
        try {
            $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;

            return CarbonImmutable::parse($value, $hasOffset ? null : (string) config('onec.timezone'))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function guid(array $record, string $field, bool $required): ?string
    {
        $value = $record[$field] ?? null;

        if ($value === null) {
            return $required ? 'required' : null;
        }

        return is_string($value) && preg_match(self::GUID_PATTERN, $value) === 1 ? null : 'invalid_guid';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function string(array $record, string $field, int $max, bool $required = true): ?string
    {
        $value = $record[$field] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '' && $required)) {
            return $required ? 'required' : null;
        }

        if (! is_string($value)) {
            return 'must_be_string';
        }

        return mb_strlen($value) > $max ? 'too_long' : null;
    }

    /**
     * Optional item accounting code (BR-I.13a): null, "" or absent are fine (= keep the stored value).
     *
     * @param  array<string, mixed>  $record
     * @param  callable(?string): ?string  $rule
     */
    protected function itemCode(array $record, string $field, callable $rule): ?string
    {
        $value = $record[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return 'must_be_string';
        }

        return $rule(ItemCodes::clean($value));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function boolean(array $record, string $field): ?string
    {
        if (! array_key_exists($field, $record)) {
            return 'required';
        }

        return is_bool($record[$field]) ? null : 'must_be_boolean';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function dateTime(array $record, string $field): ?string
    {
        if (! isset($record[$field])) {
            return 'required';
        }

        return $this->isDateTime($record[$field]) ? null : 'invalid_datetime';
    }

    protected function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return 'invalid_date';
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? null : 'invalid_date';
    }

    protected function currency(mixed $value): ?string
    {
        if ($value === null) {
            return 'required';
        }

        return is_string($value) && array_key_exists(strtoupper($value), (array) config('crm.currencies')) ? null : 'invalid_currency';
    }

    protected function vatRate(mixed $value): ?string
    {
        if ($value === null) {
            return 'required';
        }

        if (! is_string($value)) {
            return 'must_be_string';
        }

        return $value === 'exempt' || (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $value) === 1 && bccomp($value, '100', 2) <= 0) ? null : 'invalid_vat_rate';
    }

    /**
     * Optional money field (rev. 4 item prices, stock cost): absent is fine, null clears (item
     * prices) or means "no cost known" (stock); otherwise a decimal string, never a JSON number.
     *
     * @param  array<string, mixed>  $record
     */
    protected function optionalMoney(array $record, string $field, bool $allowNegative = false): ?string
    {
        $value = $record[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return 'must_be_decimal_string';
        }

        if (str_starts_with($value, '-')) {
            if (! $allowNegative) {
                return 'negative';
            }

            $value = substr($value, 1);
        }

        return preg_match(self::MONEY_PATTERN, $value) === 1 ? null : 'must_be_decimal_string';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function optionalCurrency(array $record, string $field): ?string
    {
        return ($record[$field] ?? null) === null ? null : $this->currency($record[$field]);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function quantity(array $record, string $field, bool $required, bool $allowNegative = true): ?string
    {
        if (! array_key_exists($field, $record) || $record[$field] === null) {
            return $required ? 'required' : null;
        }

        $value = $record[$field];

        if (! is_string($value) || preg_match(self::QUANTITY_PATTERN, $value) !== 1) {
            return 'must_be_decimal_string';
        }

        return ! $allowNegative && str_starts_with($value, '-') ? 'negative' : null;
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    protected function error(string $field, string $code): array
    {
        $messages = [
            'required' => ':field is required',
            'invalid_guid' => ':field must be a 36-character GUID',
            'invalid_datetime' => ':field must be an ISO 8601 datetime with offset',
            'invalid_date' => ':field must be a date YYYY-MM-DD',
            'must_be_decimal_string' => ':field must be a decimal string',
            'must_be_string' => ':field must be a string',
            'must_be_boolean' => ':field must be true or false',
            'negative' => ':field must not be negative',
            'too_long' => ':field is too long',
            'invalid_enum' => ':field has an unsupported value',
            'invalid_currency' => ':field must be an ISO 4217 currency enabled in the CRM',
            'invalid_vat_rate' => ':field must be "exempt" or a percent string such as "12"',
            'currency_mismatch' => ':field differs from the price type currency',
            'duplicate_id' => 'the same record appears more than once in this batch',
            'invalid_type' => 'record must be a JSON object',
            'invalid_format' => ':field must contain digits only',
        ];

        return ['field' => $field, 'code' => $code, 'message' => Str::replace(':field', $field, $messages[$code] ?? $code)];
    }
}
