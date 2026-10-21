<?php

namespace App\Services\OneC\Customers;

use App\Enums\CustomerOrigin;
use App\Enums\LegalType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\Partner;
use App\Models\User;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\Inbound\OneCSyncContext;
use App\Services\OneC\OneCSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies counterparties pushed by 1C with `POST /customers` (Spec 02 rev. 4): matched by the tax ID
 * (INN) ONLY. A partner with that tax ID is updated (1C wins over pending CRM edits; replaced values
 * kept in the activity log), its 1C GUID is stored as information and "Sync with 1C" is switched on.
 * Without a match a new customer is created only while `integrations.allow_create_from_1c` is on.
 * `id` (GUID) and `crm_id` are still accepted but never used for matching; records without a tax ID
 * are skipped (`tax_id_missing`). Out-of-order and hash checks as before.
 */
class CustomerInboundUpserter
{
    public function __construct(protected CustomerOutbox $outbox, protected BatchValidator $validator) {}

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed> result row: index, id, crm_id, result, errors?/candidates?
     */
    public function upsert(mixed $record, int $index): array
    {
        if (! is_array($record) || array_is_list($record)) {
            return ['index' => $index, 'id' => null, 'crm_id' => null, 'result' => 'failed', 'errors' => [['field' => null, 'code' => 'invalid_type']]];
        }

        $guid = is_string($record['id'] ?? null) ? strtolower($record['id']) : null;
        $row = ['index' => $index, 'id' => $guid, 'crm_id' => null];
        $errors = $this->errors($record);

        if ($errors !== []) {
            return [...$row, 'result' => 'failed', 'errors' => $errors];
        }

        if ($record['legal_type'] === 'individual') {
            return [...$row, 'result' => 'skipped'];
        }

        return OneCSyncContext::run(fn (): array => DB::transaction(fn (): array => $this->apply($record, $guid, $row)));
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function apply(array $record, string $guid, array $row): array
    {
        $taxId = Customer::normalizeTaxId(is_string($record['tax_id'] ?? null) ? $record['tax_id'] : null);

        // Spec 02 rev. 4: the tax ID is the only key (unique per partner); no tax ID, no sync.
        if ($taxId === null) {
            return [...$row, 'result' => 'skipped', 'errors' => [['field' => 'tax_id', 'code' => 'tax_id_missing']]];
        }

        $candidates = Partner::withTrashed()->where('tax_id', $taxId)->lockForUpdate()->orderBy('id')->get();

        if ($candidates->count() > 1) {
            return [...$row, 'result' => 'conflict', 'candidates' => $candidates->pluck('uuid')->all()];
        }

        $customer = $candidates->first();

        // The GUID is information only; it may not sit on another partner (unique column).
        $guidOwner = Partner::withTrashed()->where('external_id', $guid)->when($customer !== null, fn ($query) => $query->whereKeyNot($customer->id))->first();

        if ($guidOwner !== null) {
            return [...$row, 'result' => 'conflict', 'candidates' => [$guidOwner->uuid], 'errors' => [['field' => 'id', 'code' => 'guid_on_other_partner']]];
        }

        $linking = $customer !== null && $customer->external_id !== $guid;
        $duplicates = collect();

        $updatedAt = BatchValidator::parseDateTime((string) $record['updated_at']);
        $mapped = $this->map($record, $taxId);
        $hash = hash('sha256', (string) json_encode([$mapped, $record['contacts'] ?? [], (bool) $record['is_deleted'], $record['merged_into_id'] ?? null]));

        if ($customer !== null && ! $linking) {
            $row['crm_id'] = $customer->uuid;

            if ($customer->external_updated_at !== null && $updatedAt !== null && $updatedAt->lt($customer->external_updated_at)) {
                return [...$row, 'result' => 'stale'];
            }

            if ($customer->external_hash === $hash && $customer->sync_status === SyncStatus::Synced) {
                return [...$row, 'result' => 'unchanged'];
            }
        }

        $isNew = $customer === null;

        if ($isNew && ! app(OneCSettings::class)->allowCreateFromOneC()) {
            return [...$row, 'result' => 'skipped', 'errors' => [['field' => 'tax_id', 'code' => 'create_disabled']]];
        }

        if ($isNew) {
            $ownerId = $this->ownerId($record);

            if ($ownerId === null) {
                return [...$row, 'result' => 'failed', 'errors' => [['field' => 'manager', 'code' => 'no_owner']]];
            }

            $customer = new Customer([
                'origin' => CustomerOrigin::Onec,
                'owner_id' => $ownerId,
                'currency' => (string) config('crm.base_currency'),
            ]);
        }

        $replaced = [];

        if (! $isNew && $customer->sync_status === SyncStatus::Pending) {
            foreach ($mapped as $field => $value) {
                $current = $customer->getAttribute($field);
                $current = $current instanceof \BackedEnum ? $current->value : $current;

                if (in_array($field, CustomerOutbox::SHARED_FIELDS, true) && (string) $current !== (string) $value) {
                    $replaced[$field] = $current;
                }
            }
        }

        $customer->forceFill([
            ...$mapped,
            'external_id' => $guid,
            'external_code' => is_string($record['code'] ?? null) ? mb_substr($record['code'], 0, 50) : $customer->external_code,
            'external_version' => is_string($record['version'] ?? null) ? mb_substr($record['version'], 0, 64) : null,
            'external_updated_at' => $updatedAt,
            'external_hash' => $hash,
            'external_deleted_at' => $record['is_deleted'] ? ($customer->external_deleted_at ?? now()) : null,
            'merged_into_external_id' => is_string($record['merged_into_id'] ?? null) ? strtolower($record['merged_into_id']) : null,
            'sync_status' => SyncStatus::Synced,
            'sync_with_1c' => true,
            'sync_queued_at' => null,
            'sync_error' => null,
            'sync_error_fields' => null,
            'synced_at' => now(),
            'has_duplicate_tax_id' => $customer->has_duplicate_tax_id || $duplicates->isNotEmpty(),
        ])->save();

        if ($duplicates->isNotEmpty()) {
            Customer::withTrashed()->whereKey($duplicates->modelKeys())->update(['has_duplicate_tax_id' => true]);
        }

        $hadOpen = $this->outbox->supersedeOpen($customer, CustomerOutboxEntry::SUPERSEDED_BY_1C);

        if ($hadOpen && $replaced !== []) {
            $customer->logActivity('sync_conflict_1c_won', ['old' => $replaced, 'new' => array_intersect_key($mapped, $replaced)]);
        }

        if ($linking) {
            $customer->logActivity('sync_linked_existing', ['external_code' => $customer->external_code]);
        }

        $this->syncContacts($customer, is_array($record['contacts'] ?? null) ? $record['contacts'] : []);

        return [...$row, 'crm_id' => $customer->uuid, 'result' => $isNew ? 'created' : ($linking ? 'linked' : 'updated')];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function map(array $record, ?string $taxId): array
    {
        $string = fn (string $field, int $max): ?string => is_string($record[$field] ?? null) && trim($record[$field]) !== '' ? mb_substr(trim($record[$field]), 0, $max) : null;
        $country = strtoupper((string) ($string('country', 2) ?? config('crm.default_country')));

        return [
            'name' => (string) $string('name', 255),
            'legal_name' => $string('full_name', 255),
            'legal_type' => LegalType::from($record['legal_type'])->value,
            'tax_id' => $taxId,
            'country' => preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : (string) config('crm.default_country'),
            'city' => $string('city', 120),
            'address' => $string('address', 2000),
            'postal_code' => $string('postal_code', 20),
            'phone' => $string('phone', 32),
            'email' => filter_var($string('email', 255), FILTER_VALIDATE_EMAIL) ?: null,
        ];
    }

    /**
     * @param  list<mixed>  $contacts
     */
    protected function syncContacts(Partner $customer, array $contacts): void
    {
        foreach ($contacts as $data) {
            if (! is_array($data) || ! is_string($data['first_name'] ?? null) || trim($data['first_name']) === '') {
                continue;
            }

            $guid = is_string($data['id'] ?? null) && preg_match(BatchValidator::GUID_PATTERN, $data['id']) === 1 ? strtolower($data['id']) : null;
            $contact = $guid === null ? null : Contact::withTrashed()->where('external_id', $guid)->first();

            if ($contact === null && is_string($data['crm_id'] ?? null) && Str::isUuid($data['crm_id'])) {
                $contact = Contact::withTrashed()->where('partner_id', $customer->id)->where('uuid', strtolower($data['crm_id']))->first();
            }

            $deleted = (bool) ($data['is_deleted'] ?? false);

            if ($contact === null && $deleted) {
                continue;
            }

            $contact ??= new Contact(['partner_id' => $customer->id]);
            $contact->forceFill([
                'first_name' => mb_substr(trim($data['first_name']), 0, 120),
                'last_name' => is_string($data['last_name'] ?? null) ? mb_substr($data['last_name'], 0, 120) : null,
                'position' => is_string($data['position'] ?? null) ? mb_substr($data['position'], 0, 120) : null,
                'phone' => is_string($data['phone'] ?? null) ? mb_substr($data['phone'], 0, 32) : null,
                'email' => filter_var($data['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
                'external_id' => $guid ?? $contact->external_id,
                'synced_at' => now(),
                'external_deleted_at' => $deleted ? ($contact->external_deleted_at ?? now()) : null,
            ])->save();

            if ($deleted && ! $contact->trashed()) {
                $contact->delete();
            } elseif (! $deleted && $contact->trashed()) {
                $contact->restore();
            }
        }

        if (! $customer->contacts()->where('is_primary', true)->exists()) {
            $customer->contacts()->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
        }
    }

    /**
     * Owner at first import (BR-I.16): the CRM user with the 1C manager's e-mail, else the
     * configured default owner, else the first active manager or admin.
     *
     * @param  array<string, mixed>  $record
     */
    protected function ownerId(array $record): ?int
    {
        $internal = fn () => User::query()->internal()->active();
        $email = is_array($record['manager'] ?? null) && is_string($record['manager']['email'] ?? null) ? mb_strtolower(trim($record['manager']['email'])) : null;

        return ($email !== null ? $internal()->where('email', $email)->value('id') : null)
            ?? (filled(config('onec.customers.default_owner_email')) ? $internal()->where('email', mb_strtolower((string) config('onec.customers.default_owner_email')))->value('id') : null)
            ?? $internal()->where('role', UserRole::Manager)->orderBy('id')->value('id')
            ?? $internal()->where('role', UserRole::Admin)->orderBy('id')->value('id');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return list<array{field: string, code: string}>
     */
    protected function errors(array $record): array
    {
        $errors = [];
        $add = function (string $field, ?string $code) use (&$errors): void {
            if ($code !== null) {
                $errors[] = ['field' => $field, 'code' => $code];
            }
        };

        $add('id', is_string($record['id'] ?? null) ? (preg_match(BatchValidator::GUID_PATTERN, $record['id']) === 1 ? null : 'invalid_guid') : 'required');
        $add('name', is_string($record['name'] ?? null) && trim($record['name']) !== '' ? (mb_strlen($record['name']) > 255 ? 'too_long' : null) : 'required');
        $add('legal_type', in_array($record['legal_type'] ?? null, ['legal_entity', 'sole_proprietor', 'individual'], true) ? null : 'invalid_enum');
        $add('is_deleted', is_bool($record['is_deleted'] ?? null) ? null : 'must_be_boolean');
        $add('updated_at', $this->validator->isDateTime($record['updated_at'] ?? null) ? null : 'invalid_datetime');
        $add('crm_id', ! isset($record['crm_id']) || (is_string($record['crm_id']) && Str::isUuid($record['crm_id'])) ? null : 'invalid_guid');
        $add('tax_id', ! isset($record['tax_id']) || (is_string($record['tax_id']) && mb_strlen($record['tax_id']) <= 32) ? null : 'must_be_string');
        $add('contacts', ! isset($record['contacts']) || (is_array($record['contacts']) && array_is_list($record['contacts'])) ? null : 'invalid_type');

        return $errors;
    }
}
