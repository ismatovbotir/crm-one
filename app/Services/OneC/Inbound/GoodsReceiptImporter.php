<?php

namespace App\Services\OneC\Inbound;

use App\Actions\Stock\TransitionGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ItemType;
use App\Enums\ReceiptSource;
use App\Enums\UserRole;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\NumberSequence;
use App\Models\Partner;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriods;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Goods receipts pushed by 1C (`POST /goods-receipts`, Spec 06 section 26, Spec 02 rev. 5).
 * 1C owns the receipt: document id/number/date, supplier (by tax ID only), warehouse and the
 * expected lines. The push is self-contained: a supplier that is not in the CRM is created as a
 * partner with the supplier role, "Sync with 1C" on and a review flag; an item that is not in the
 * CRM is created from the minimal 1C fields with a review flag.
 *
 * Document linking (Spec 02 rev. 5 section 10.6): the record is matched by `crm_id` (the CRM
 * document id 1C stored from an earlier answer), then by the 1C document id; no match creates it.
 * Every answer carries the CRM id, number and version and echoes the 1C id. An update with an
 * older `version` or `updated_at` than the CRM holds is stale. Same content is `unchanged`.
 * Updates apply only before posting: a change to a posted receipt is rejected and flagged for the
 * stock manager; serial-linked data is never rewritten (a line with serials keeps its item and is
 * never removed); a 1C delete of a receipt with serials becomes
 * a cancel request for review. One transaction per receipt; no outbound call.
 */
class GoodsReceiptImporter
{
    public const MAX_LINES = 500;

    /**
     * @return array<string, mixed> index, id, crm_id, number, version, result, errors?, created?, review_flagged?
     */
    public function upsert(mixed $record, int $index, ?string $pathCrmId = null): array
    {
        if (! is_array($record) || array_is_list($record)) {
            return ['index' => $index, 'id' => null, 'crm_id' => $pathCrmId, 'number' => null, 'version' => null, 'result' => 'failed', 'errors' => [['field' => null, 'code' => 'invalid_type']]];
        }

        if ($pathCrmId !== null) {
            $record['crm_id'] = $pathCrmId;
        }

        $guid = is_string($record['id'] ?? null) && preg_match(BatchValidator::GUID_PATTERN, $record['id']) === 1 ? strtolower($record['id']) : null;
        $row = ['index' => $index, 'id' => $guid, 'crm_id' => null, 'number' => null, 'version' => null];
        $errors = $this->errors($record, $guid);

        if ($errors !== []) {
            return [...$row, 'result' => 'failed', 'errors' => $errors];
        }

        $warehouse = Warehouse::query()->where('external_id', strtolower((string) $record['warehouse_id']))->first();

        if ($warehouse === null) {
            return [...$row, 'result' => 'failed', 'errors' => [['field' => 'warehouse_id', 'code' => 'unknown_warehouse']]];
        }

        $hash = hash('sha256', (string) json_encode([$record['number'], $record['date'], strtolower((string) $record['warehouse_id']), $record['supplier'], $record['lines'], (bool) ($record['is_deleted'] ?? false)]));

        try {
            return $this->applyOnce($record, (string) $guid, $warehouse, $hash, $row);
        } catch (UniqueConstraintViolationException) {
            // A parallel push created the same receipt first: the whole attempt was rolled back, so
            // run it again and it takes the update path.
            return $this->applyOnce($record, (string) $guid, $warehouse, $hash, $row);
        } catch (ReceiptRejected $rejected) {
            // Nothing of this receipt is kept (no partial create).
            return [...$rejected->row, 'result' => 'failed', 'errors' => [['field' => $rejected->field, 'code' => $rejected->errorCode]]];
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function applyOnce(array $record, string $guid, Warehouse $warehouse, string $hash, array $row): array
    {
        return OneCSyncContext::run(fn (): array => DB::transaction(fn (): array => $this->apply($record, $guid, $warehouse, $hash, $row)));
    }

    /**
     * Plain read first, then a lock on the found row by primary key. Locking the search itself
     * would take a gap lock when nothing matches; a racing insert is settled by the unique index.
     */
    protected function lockedReceipt(string $column, string $value): ?GoodsReceipt
    {
        $id = GoodsReceipt::query()->where($column, $value)->value('id');

        return $id === null ? null : GoodsReceipt::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function apply(array $record, string $guid, Warehouse $warehouse, string $hash, array $row): array
    {
        $crmId = is_string($record['crm_id'] ?? null) ? strtolower($record['crm_id']) : null;
        $receipt = $crmId !== null ? $this->lockedReceipt('uuid', $crmId) : null;

        if ($receipt !== null && (! $receipt->isFromOneC() || $receipt->external_id !== $guid)) {
            return [...$row, 'crm_id' => $receipt->uuid, 'result' => 'failed', 'errors' => [['field' => 'crm_id', 'code' => $receipt->isFromOneC() ? 'id_mismatch' : 'not_from_1c']]];
        }

        $receipt ??= $this->lockedReceipt('external_id', $guid);
        $deleted = (bool) ($record['is_deleted'] ?? false);
        $updatedAt = is_string($record['updated_at'] ?? null) ? BatchValidator::parseDateTime($record['updated_at']) : null;

        if ($receipt === null && $deleted) {
            return [...$row, 'result' => 'unchanged'];
        }

        if ($receipt !== null) {
            $row = [...$row, 'crm_id' => $receipt->uuid, 'number' => $receipt->number, 'version' => $receipt->sync_version];

            if (is_int($record['version'] ?? null) && $record['version'] < $receipt->sync_version) {
                return [...$row, 'result' => 'failed', 'errors' => [['field' => 'version', 'code' => 'stale_version']]];
            }

            if ($updatedAt !== null && $receipt->external_updated_at !== null && $updatedAt->lt($receipt->external_updated_at)) {
                return [...$row, 'result' => 'failed', 'errors' => [['field' => 'updated_at', 'code' => 'stale_update']]];
            }

            if ($receipt->external_hash === $hash) {
                return [...$row, 'result' => 'unchanged'];
            }

            if ($receipt->status === GoodsReceiptStatus::Posted) {
                return [...$row, ...$this->flagPosted($receipt, $record, $hash)];
            }

            if ($receipt->status === GoodsReceiptStatus::Cancelled) {
                return [...$row, 'result' => 'failed', 'errors' => [['field' => 'id', 'code' => 'receipt_cancelled']]];
            }

            if ($deleted) {
                return [...$row, ...$this->cancel($receipt, $record, $hash, $updatedAt)];
            }
        }

        $created = ['partner' => false, 'items' => []];
        $supplier = $this->supplier((array) $record['supplier'], $created);

        if ($supplier === null) {
            throw new ReceiptRejected('supplier', 'no_owner', $row);
        }
        $lines = [];

        foreach ($record['lines'] as $position => $line) {
            $item = $this->item((array) $line['item'], $created);

            if (is_string($item)) {
                throw new ReceiptRejected('lines.'.$position.'.item'.($item === 'unknown_unit' ? '.unit_id' : '.code'), $item, $row);
            }

            $lines[] = ['line_id' => strtolower((string) $line['line_id']), 'item' => $item, 'expected' => Quantity::normalize((string) $line['expected_qty'])];
        }

        $isNew = $receipt === null;

        if ($isNew) {
            $year = (int) AccountingPeriods::today()->year;
            $receipt = GoodsReceipt::query()->create([
                'number' => NumberSequence::format('GR', $year, NumberSequence::next('GR', $year)),
                'status' => GoodsReceiptStatus::ReceivedFromOneC,
                'source_type' => ReceiptSource::SupplierDelivery,
                'warehouse_id' => $warehouse->id,
                'received_on' => (string) $record['date'],
                'origin' => 'onec',
                'external_id' => $guid,
                'sync_version' => 1,
            ]);
            TransitionGoodsReceipt::record($receipt, null, GoodsReceiptStatus::ReceivedFromOneC, null, 'received_from_1c');
        }

        $receipt->forceFill([
            'warehouse_id' => $warehouse->id,
            'external_number' => mb_substr((string) $record['number'], 0, 50),
            'external_date' => (string) $record['date'],
            'supplier_doc_ref' => mb_substr((string) $record['number'], 0, 60),
            'supplier_partner_id' => $supplier->id,
            'counterparty_name' => mb_substr($supplier->name, 0, 255),
            'external_hash' => $hash,
            'external_updated_at' => $updatedAt ?? $receipt->external_updated_at,
            'sync_version' => $isNew ? 1 : $receipt->sync_version + 1,
        ])->save();

        $conflict = $this->syncLines($receipt, $lines);

        if ($conflict !== null) {
            throw new ReceiptRejected($conflict['field'], $conflict['code'], $row);
        }

        // A checked receipt that 1C changed goes back to the stock manager.
        if (! $isNew && $receipt->status === GoodsReceiptStatus::Checked) {
            $receipt->forceFill(['status' => GoodsReceiptStatus::InProgress])->save();
            TransitionGoodsReceipt::record($receipt, GoodsReceiptStatus::Checked, GoodsReceiptStatus::InProgress, null, 'updated_from_1c');
        }

        $receipt->logActivity($isNew ? 'received_from_1c' : 'updated_from_1c', ['external_number' => $record['number'], 'version' => $receipt->sync_version, 'created' => $created]);

        return [...$row, 'crm_id' => $receipt->uuid, 'number' => $receipt->number, 'version' => $receipt->sync_version, 'result' => $isNew ? 'created' : 'updated', 'created' => $created];
    }

    /**
     * 1C changed a posted receipt: nothing is rewritten; the receipt is flagged so the stock
     * manager reviews the difference (logged) and proposes a correction.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function flagPosted(GoodsReceipt $receipt, array $record, string $hash): array
    {
        $receipt->forceFill(['needs_review' => true, 'review_reason' => 'onec_update_after_posting'])->save();
        $receipt->logActivity('onec_update_rejected', [
            'external_number' => $record['number'] ?? null,
            'is_deleted' => (bool) ($record['is_deleted'] ?? false),
            'hash' => $hash,
            'lines' => collect((array) $record['lines'])->map(fn (mixed $line): array => [
                'line_id' => is_array($line) ? ($line['line_id'] ?? null) : null,
                'item_id' => is_array($line) && is_array($line['item'] ?? null) ? ($line['item']['id'] ?? null) : null,
                'expected_qty' => is_array($line) ? ($line['expected_qty'] ?? null) : null,
            ])->all(),
        ]);

        return ['result' => 'failed', 'review_flagged' => true, 'errors' => [['field' => 'id', 'code' => 'receipt_posted']]];
    }

    /**
     * 1C deleted/cancelled its document: without entered serials the receipt is cancelled; with
     * serials it becomes a cancel request for the warehouse head (serials are never dropped silently).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function cancel(GoodsReceipt $receipt, array $record, string $hash, ?CarbonImmutable $updatedAt): array
    {
        $hasSerials = DocumentLineSerial::query()->where('document_type', GoodsReceipt::DOCUMENT_TYPE)->where('document_id', $receipt->id)->where('status', DocumentLineSerial::DRAFT)->exists();
        $attributes = ['external_hash' => $hash, 'external_updated_at' => $updatedAt ?? $receipt->external_updated_at, 'sync_version' => $receipt->sync_version + 1];

        if ($hasSerials) {
            $receipt->forceFill([...$attributes, 'needs_review' => true, 'review_reason' => 'cancel_requested_by_1c'])->save();
            $receipt->logActivity('cancel_requested_by_1c', ['external_number' => $record['number'] ?? null]);

            return ['version' => $receipt->sync_version, 'result' => 'cancel_requested', 'review_flagged' => true];
        }

        $from = $receipt->status;
        $receipt->forceFill([...$attributes, 'status' => GoodsReceiptStatus::Cancelled, 'needs_review' => false, 'review_reason' => null])->save();
        TransitionGoodsReceipt::record($receipt, $from, GoodsReceiptStatus::Cancelled, null, 'cancelled_in_1c');

        return ['version' => $receipt->sync_version, 'result' => 'cancelled'];
    }

    /**
     * Expected lines are 1C's, matched by 1C line id. A line with serials entered keeps its item
     * and is never removed (conflict: nothing of the update is kept); a lower expected quantity
     * shows as an overage at checking. Non-serialized received quantities start at the expected
     * ones and follow 1C until the receipt is started; serialized ones count serial numbers.
     *
     * @param  list<array{line_id: string, item: Item, expected: string}>  $lines
     * @return array{field: string, code: string}|null
     */
    protected function syncLines(GoodsReceipt $receipt, array $lines): ?array
    {
        $existing = GoodsReceiptLine::query()->where('receipt_id', $receipt->id)->withCount('serialRows')->get()->keyBy('external_line_id');
        $untouched = $receipt->status === GoodsReceiptStatus::ReceivedFromOneC;
        $seen = [];

        foreach ($lines as $position => $data) {
            $seen[] = $data['line_id'];
            $line = $existing->get($data['line_id']);
            $serialized = (bool) $data['item']->is_serialized;

            if ($line === null) {
                $receipt->lines()->create([
                    'position' => $position + 1,
                    'item_id' => $data['item']->id,
                    'is_serialized' => $serialized,
                    'expected_qty' => $data['expected'],
                    'received_qty' => $serialized ? '0' : $data['expected'],
                    'external_line_id' => $data['line_id'],
                ]);

                continue;
            }

            if ($line->serial_rows_count > 0 && $line->item_id !== $data['item']->id) {
                return ['field' => 'lines.'.$position.'.item.id', 'code' => 'line_has_serials'];
            }

            $attributes = ['position' => $position + 1, 'item_id' => $data['item']->id, 'is_serialized' => $serialized, 'expected_qty' => $data['expected']];

            if ($untouched && ! $serialized) {
                $attributes['received_qty'] = $data['expected'];
            }

            $line->forceFill($attributes)->save();
        }

        foreach ($existing->toBase()->except($seen) as $removed) {
            if ($removed->serial_rows_count > 0) {
                return ['field' => 'lines', 'code' => 'line_has_serials'];
            }

            $removed->delete();
        }

        return null;
    }

    /**
     * Supplier by tax ID only; created (supplier role, sync on, needs review) when missing. An
     * existing partner is used as it is (its roles are not changed). Null: nobody can own a new one.
     *
     * @param  array<string, mixed>  $data
     * @param  array{partner: bool, items: list<string>}  $created
     */
    protected function supplier(array $data, array &$created): ?Partner
    {
        $taxId = Customer::normalizeTaxId((string) $data['tax_id']);
        $partner = Partner::withTrashed()->where('tax_id', $taxId)->orderBy('id')->first();

        if ($partner !== null) {
            return $partner;
        }

        $ownerId = $this->ownerId();

        if ($ownerId === null) {
            return null;
        }

        $name = trim((string) ($data['name'] ?? '')) !== '' ? trim((string) $data['name']) : (string) $taxId;
        $partner = new Partner([
            'origin' => 'onec',
            'name' => mb_substr($name, 0, 255),
            'legal_name' => isset($data['full_name']) ? mb_substr((string) $data['full_name'], 0, 255) : null,
            'legal_type' => 'legal_entity',
            'tax_id' => $taxId,
            'country' => is_string($data['country'] ?? null) && preg_match('/^[A-Za-z]{2}$/', $data['country']) === 1 ? strtoupper($data['country']) : (string) config('crm.default_country', 'UZ'),
            'currency' => (string) config('crm.base_currency', 'UZS'),
            'owner_id' => $ownerId,
            'sync_with_1c' => true,
            'sync_status' => 'not_synced',
            'needs_review' => true,
            'review_reason' => 'created_from_1c_receipt',
        ]);
        $partner->forceFill(['is_customer' => false, 'is_supplier' => true, 'is_carrier' => false])->save();

        $profile = new Counterparty([...$partner->only(Customer::IDENTITY_FIELDS), 'is_supplier' => true, 'is_carrier' => false, 'status' => 'prospect', 'owner_id' => $partner->owner_id]);
        $profile->partner_id = $partner->id;
        $profile->save();
        $partner->logActivity('created_from_1c_receipt', ['tax_id' => $taxId]);
        $created['partner'] = true;

        return $partner;
    }

    /**
     * Item by its 1C key (GUID); created from the minimal fields with a review flag when missing
     * (or an error code: unknown unit, code used by another item).
     *
     * @param  array<string, mixed>  $data
     * @param  array{partner: bool, items: list<string>}  $created
     */
    protected function item(array $data, array &$created): Item|string
    {
        $guid = strtolower((string) $data['id']);
        $item = Item::withTrashed()->where('external_id', $guid)->first();

        if ($item !== null) {
            return $item;
        }

        $unitId = Unit::query()->where('external_id', strtolower((string) ($data['unit_id'] ?? '')))->value('id');

        if ($unitId === null) {
            return 'unknown_unit';
        }

        // The code belongs to another item (not linked to this 1C key): a person must link them.
        if (Item::withTrashed()->where('sku', mb_substr((string) $data['code'], 0, 64))->exists()) {
            return 'item_code_taken';
        }

        $item = Item::query()->create([
            'external_id' => $guid,
            'sku' => mb_substr((string) $data['code'], 0, 64),
            'name' => mb_substr((string) $data['name'], 0, 255),
            'type' => ItemType::Product->value,
            'unit_id' => $unitId,
            'tax_rate_bp' => (int) config('crm.default_tax_rate_bp', 1200),
            'is_active' => true,
            'is_serialized' => false,
            'portal_visible' => false,
            'needs_review' => true,
            'review_reason' => 'created_from_1c_receipt',
        ]);
        $item->logActivity('created_from_1c_receipt', ['code' => $item->sku]);
        $created['items'][] = $item->sku;

        return $item;
    }

    protected function ownerId(): ?int
    {
        $internal = fn () => User::query()->internal()->active();

        return (filled(config('onec.customers.default_owner_email')) ? $internal()->where('email', mb_strtolower((string) config('onec.customers.default_owner_email')))->value('id') : null)
            ?? $internal()->where('role', UserRole::Manager)->orderBy('id')->value('id')
            ?? $internal()->where('role', UserRole::Admin)->orderBy('id')->value('id');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{field: string, code: string}>
     */
    protected function errors(array $record, ?string $guid): array
    {
        $errors = [];
        $add = function (string $field, string $code) use (&$errors): void {
            $errors[] = ['field' => $field, 'code' => $code];
        };

        if ($guid === null) {
            $add('id', 'required_uuid');
        }

        if (($record['crm_id'] ?? null) !== null && (! is_string($record['crm_id']) || preg_match(BatchValidator::GUID_PATTERN, $record['crm_id']) !== 1)) {
            $add('crm_id', 'invalid_uuid');
        }

        if (($record['version'] ?? null) !== null && (! is_int($record['version']) || $record['version'] < 1)) {
            $add('version', 'invalid_version');
        }

        if (($record['updated_at'] ?? null) !== null && ! app(BatchValidator::class)->isDateTime($record['updated_at'])) {
            $add('updated_at', 'invalid_datetime');
        }

        if (! is_string($record['number'] ?? null) || trim($record['number']) === '') {
            $add('number', 'required');
        }

        if (! is_string($record['date'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['date']) !== 1) {
            $add('date', 'invalid_date');
        }

        if (! is_string($record['warehouse_id'] ?? null) || preg_match(BatchValidator::GUID_PATTERN, $record['warehouse_id']) !== 1) {
            $add('warehouse_id', 'required_uuid');
        }

        $supplier = $record['supplier'] ?? null;

        if (! is_array($supplier) || Customer::normalizeTaxId(is_string($supplier['tax_id'] ?? null) ? $supplier['tax_id'] : null) === null) {
            $add('supplier.tax_id', 'tax_id_required');
        }

        $lines = $record['lines'] ?? null;

        if (! is_array($lines) || ! array_is_list($lines) || $lines === [] || count($lines) > self::MAX_LINES) {
            $add('lines', 'between_1_and_'.self::MAX_LINES);

            return $errors;
        }

        $lineIds = [];

        foreach ($lines as $position => $line) {
            $prefix = 'lines.'.$position;

            if (! is_array($line)) {
                $add($prefix, 'invalid_type');

                continue;
            }

            if (! is_string($line['line_id'] ?? null) || preg_match(BatchValidator::GUID_PATTERN, $line['line_id']) !== 1) {
                $add($prefix.'.line_id', 'required_uuid');
            } elseif (in_array(strtolower($line['line_id']), $lineIds, true)) {
                $add($prefix.'.line_id', 'duplicate');
            } else {
                $lineIds[] = strtolower($line['line_id']);
            }

            $item = $line['item'] ?? null;

            if (! is_array($item) || ! is_string($item['id'] ?? null) || preg_match(BatchValidator::GUID_PATTERN, $item['id']) !== 1) {
                $add($prefix.'.item.id', 'required_uuid');
            } elseif (! Item::withTrashed()->where('external_id', strtolower($item['id']))->exists() && (! is_string($item['code'] ?? null) || trim($item['code']) === '' || ! is_string($item['name'] ?? null) || trim($item['name']) === '')) {
                $add($prefix.'.item', 'item_fields_required');
            }

            $qty = $line['expected_qty'] ?? null;

            if (! is_string($qty) || preg_match('/^\d{1,12}(\.\d{1,3})?$/', $qty) !== 1 || bccomp($qty, '0', 3) <= 0) {
                $add($prefix.'.expected_qty', 'invalid_quantity');
            }
        }

        return $errors;
    }
}
