<?php

namespace App\Services\OneC\Customers;

use App\Enums\LegalType;
use App\Enums\SyncStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\Partner;
use App\Models\User;
use App\Services\OneC\Inbound\BatchValidator;
use App\Services\OneC\OneCSettings;
use App\Services\OneC\Outbox\OneCOutbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Outbox of CRM customers waiting for 1C (Spec 02 rev. 3, BR-I.6..I.8). 1C is on a private
 * network: the CRM never calls it. 1C polls open entries and acknowledges them.
 */
class CustomerOutbox
{
    /** Shared customer fields sent to 1C; a change to any of them re-queues (BR-I.10). */
    public const SHARED_FIELDS = ['name', 'legal_name', 'legal_type', 'tax_id', 'country', 'city', 'address', 'postal_code', 'phone', 'email'];

    /** Shared contact fields (inside the customer aggregate). */
    public const SHARED_CONTACT_FIELDS = ['first_name', 'last_name', 'position', 'phone', 'email', 'is_primary', 'deleted_at'];

    public function __construct(protected OneCSettings $settings) {}

    /**
     * Why the customer cannot be sent to 1C (BR-I.6), or null when it can.
     */
    public function notPushableReason(Partner $customer): ?string
    {
        return match (true) {
            ! $this->settings->customersEnabled() => 'integration_disabled',
            $customer->trashed() || $customer->isDeletedInOneC() => 'archived',
            blank($customer->tax_id) => 'tax_id_missing',
            preg_match('/^\d{9}$/', (string) $customer->tax_id) !== 1 => 'tax_id_invalid',
            ! in_array($customer->legal_type, [LegalType::LegalEntity, LegalType::SoleProprietor, LegalType::Government], true) => 'legal_type_not_supported',
            default => null,
        };
    }

    /**
     * Open (or keep) the outbox entry of a customer (BR-I.7). Returns null when the customer is
     * not pushable; its status then becomes `not_synced` with the reason. While the exchange is
     * switched off nothing changes (the caller records `integration_disabled` on creation).
     */
    public function queue(Partner $customer, string $reason, ?User $by = null): ?CustomerOutboxEntry
    {
        if (! $this->settings->customersEnabled()) {
            return null;
        }

        // Spec 02 rev. 4: only partners with "Sync with 1C" on are offered (even on resync).
        if (! $customer->sync_with_1c) {
            return null;
        }

        $notPushable = $this->notPushableReason($customer);

        if ($notPushable !== null) {
            DB::transaction(function () use ($customer, $notPushable): void {
                $this->openEntries($customer)->each(fn (CustomerOutboxEntry $entry) => $entry->forceFill(['superseded_at' => now(), 'superseded_reason' => 'not_pushable'])->save());

                if (! $customer->isLinkedToOneC() || $customer->sync_status === SyncStatus::Pending) {
                    $customer->forceFill(['sync_status' => $customer->isLinkedToOneC() ? SyncStatus::Failed : SyncStatus::NotSynced, 'sync_error' => $notPushable, 'sync_queued_at' => null])->saveQuietly();
                }
            });

            return null;
        }

        return DB::transaction(function () use ($customer, $reason, $by): CustomerOutboxEntry {
            $open = $this->openEntries($customer)->last();

            if ($open !== null && ! $open->wasDelivered()) {
                if ($reason === CustomerOutboxEntry::REASON_RESYNC && $open->reason !== $reason) {
                    $open->forceFill(['reason' => $reason])->save();
                }

                $this->markPending($customer, $open);

                return $open;
            }

            if ($open !== null) {
                $open->forceFill(['superseded_at' => now(), 'superseded_reason' => CustomerOutboxEntry::SUPERSEDED_EDITED])->save();
            }

            $entry = CustomerOutboxEntry::query()->create([
                'customer_id' => $customer->id,
                'reason' => $reason,
                'queued_by' => $by?->id,
                'queued_at' => now(),
            ]);

            $this->markPending($customer, $entry);
            $customer->logActivity('sync_queued', ['reason' => $reason]);

            return $entry;
        });
    }

    /**
     * Queue every `not_synced` CRM customer that is pushable (BR-I.22: after enabling the exchange).
     */
    public function queueNotSynced(?User $by = null): int
    {
        $queued = 0;

        Customer::query()
            ->where('sync_with_1c', true)
            ->where('sync_status', SyncStatus::NotSynced)
            ->whereNull('external_deleted_at')
            ->chunkById(200, function ($customers) use (&$queued, $by): void {
                foreach ($customers as $customer) {
                    $reason = $customer->isLinkedToOneC() ? CustomerOutboxEntry::REASON_UPDATED : CustomerOutboxEntry::REASON_CREATED;

                    if ($this->queue($customer, $reason, $by) !== null) {
                        $queued++;
                    }
                }
            });

        return $queued;
    }

    /**
     * Open entries after the cursor, oldest first; marks them delivered (BR-I.7).
     *
     * @return array{entries: Collection<int, CustomerOutboxEntry>, has_more: bool}
     */
    public function poll(int $cursor, int $limit): array
    {
        $entries = CustomerOutboxEntry::query()
            ->open()
            ->where('id', '>', $cursor)
            ->whereHas('customer', fn ($query) => $query->withTrashed()->where('sync_with_1c', true))
            ->with(['customer.contacts' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $entries->count() > $limit;
        $entries = $entries->take($limit)->values();

        if ($entries->isNotEmpty()) {
            CustomerOutboxEntry::query()->whereKey($entries->modelKeys())->increment('delivery_count', 1, ['last_delivered_at' => now()]);
            CustomerOutboxEntry::query()->whereKey($entries->modelKeys())->whereNull('first_delivered_at')->update(['first_delivered_at' => now()]);
            $entries->each->refresh();
        }

        return ['entries' => new Collection($entries->all()), 'has_more' => $hasMore];
    }

    /**
     * Apply one acknowledgement from 1C (BR-I.8). Idempotent.
     *
     * @param  array<string, mixed>  $ack
     * @return array{result: string, crm_id: string|null, outbox_id: int|null, errors?: list<array{field: string|null, code: string}>}
     */
    public function acknowledge(array $ack): array
    {
        $crmId = is_string($ack['crm_id'] ?? null) ? strtolower($ack['crm_id']) : null;
        $outboxId = is_int($ack['outbox_id'] ?? null) ? $ack['outbox_id'] : null;
        $base = ['crm_id' => $crmId, 'outbox_id' => $outboxId];

        $errors = $this->ackErrors($ack);

        if ($errors !== []) {
            return [...$base, 'result' => 'rejected', 'errors' => $errors];
        }

        $status = $ack['status'];
        $externalId = isset($ack['external_id']) ? strtolower((string) $ack['external_id']) : null;

        return DB::transaction(function () use ($ack, $base, $crmId, $outboxId, $status, $externalId): array {
            $customer = Customer::withTrashed()->where('uuid', $crmId)->lockForUpdate()->first();

            if ($customer === null) {
                return $this->rejected($base, 'crm_id', 'unknown_crm_id');
            }

            $entry = $outboxId !== null
                ? CustomerOutboxEntry::query()->where('customer_id', $customer->id)->find($outboxId)
                : ($this->openEntries($customer)->last() ?? CustomerOutboxEntry::query()->where('customer_id', $customer->id)->whereNotNull('acked_at')->latest('id')->first());

            if ($entry === null) {
                return $this->rejected($base, $outboxId !== null ? 'outbox_id' : null, $outboxId !== null ? 'unknown_outbox_id' : 'nothing_pending');
            }

            $base['outbox_id'] = $entry->id;

            if ($entry->acked_at !== null) {
                $same = $entry->ack_status === $status && ($status === 'failed' || $entry->ack_external_id === $externalId);

                return $same ? [...$base, 'result' => 'already_applied'] : $this->rejected($base, 'status', 'already_acknowledged');
            }

            if ($status === 'synced') {
                if (Customer::withTrashed()->where('external_id', $externalId)->whereKeyNot($customer->id)->exists()) {
                    return $this->rejected($base, 'external_id', 'external_id_taken');
                }

                if ($customer->isLinkedToOneC() && $customer->external_id !== $externalId) {
                    return $this->rejected($base, 'external_id', 'external_id_mismatch');
                }
            }

            $superseded = $entry->superseded_at !== null;

            $entry->forceFill([
                'acked_at' => now(),
                'ack_status' => $status,
                'ack_external_id' => $externalId,
                'ack_error' => $status === 'failed' ? $this->errorPayload($ack['error'] ?? null) : null,
            ])->save();

            if ($status === 'synced') {
                $this->applySynced($customer, $ack, $externalId, $superseded);
            } elseif (! $superseded) {
                $error = $this->errorPayload($ack['error'] ?? null);
                $customer->forceFill([
                    'sync_status' => SyncStatus::Failed,
                    'sync_error' => Str::limit((string) ($error['message'] ?? $error['code'] ?? 'failed'), 250),
                    'sync_error_fields' => $error['fields'] ?? null,
                    'sync_queued_at' => null,
                ])->saveQuietly();
                $customer->logActivity('sync_failed', ['code' => $error['code'] ?? null, 'message' => $error['message'] ?? null]);
            }

            return [...$base, 'result' => $superseded ? 'superseded' : 'applied'];
        });
    }

    /**
     * Supersede every open entry (1C pushed the customer and won, BR-I.11).
     */
    public function supersedeOpen(Partner $customer, string $reason): bool
    {
        $open = $this->openEntries($customer);
        $open->each(fn (CustomerOutboxEntry $entry) => $entry->forceFill(['superseded_at' => now(), 'superseded_reason' => $reason])->save());

        return $open->isNotEmpty();
    }

    /**
     * @return Collection<int, CustomerOutboxEntry>
     */
    public function openEntries(Partner $customer): Collection
    {
        return CustomerOutboxEntry::query()->where('customer_id', $customer->id)->open()->orderBy('id')->get();
    }

    /**
     * @param  array<string, mixed>  $ack
     */
    protected function applySynced(Partner $customer, array $ack, string $externalId, bool $superseded): void
    {
        $customer->forceFill([
            'external_id' => $externalId,
            'external_code' => isset($ack['external_code']) ? mb_substr((string) $ack['external_code'], 0, 50) : $customer->external_code,
            'external_version' => isset($ack['external_version']) ? mb_substr((string) $ack['external_version'], 0, 64) : $customer->external_version,
            'synced_at' => now(),
            'sync_error' => null,
            'sync_error_fields' => null,
            ...($superseded ? [] : ['sync_status' => SyncStatus::Synced, 'sync_queued_at' => null]),
        ])->saveQuietly();

        // Spec 06 9.2: order-feed events held for this customer can go to 1C now.
        app(OneCOutbox::class)->releaseCustomer($customer);

        foreach (is_array($ack['contacts'] ?? null) ? $ack['contacts'] : [] as $link) {
            if (! is_array($link) || ! is_string($link['crm_id'] ?? null) || ! is_string($link['external_id'] ?? null) || preg_match(BatchValidator::GUID_PATTERN, $link['external_id']) !== 1) {
                continue;
            }

            $guid = strtolower($link['external_id']);

            if (Contact::withTrashed()->where('external_id', $guid)->exists()) {
                continue;
            }

            Contact::withTrashed()->where('partner_id', $customer->id)->where('uuid', strtolower($link['crm_id']))->first()?->forceFill(['external_id' => $guid, 'synced_at' => now()])->saveQuietly();
        }

        $customer->logActivity($superseded ? 'sync_ack_superseded' : 'sync_succeeded', ['external_code' => $customer->external_code]);
    }

    protected function markPending(Partner $customer, CustomerOutboxEntry $entry): void
    {
        $customer->forceFill([
            'sync_status' => SyncStatus::Pending,
            'sync_queued_at' => $customer->sync_status === SyncStatus::Pending && $customer->sync_queued_at !== null ? $customer->sync_queued_at : $entry->queued_at,
            'sync_error' => null,
            'sync_error_fields' => null,
        ])->saveQuietly();
    }

    /**
     * @param  array<string, mixed>  $ack
     * @return list<array{field: string|null, code: string}>
     */
    protected function ackErrors(array $ack): array
    {
        $errors = [];

        if (! is_string($ack['crm_id'] ?? null) || ! Str::isUuid($ack['crm_id'])) {
            $errors[] = ['field' => 'crm_id', 'code' => 'validation_failed'];
        }

        if (! in_array($ack['status'] ?? null, ['synced', 'failed'], true)) {
            $errors[] = ['field' => 'status', 'code' => 'validation_failed'];
        }

        if (array_key_exists('outbox_id', $ack) && $ack['outbox_id'] !== null && ! is_int($ack['outbox_id'])) {
            $errors[] = ['field' => 'outbox_id', 'code' => 'validation_failed'];
        }

        if (($ack['status'] ?? null) === 'synced' && (! is_string($ack['external_id'] ?? null) || preg_match(BatchValidator::GUID_PATTERN, $ack['external_id']) !== 1)) {
            $errors[] = ['field' => 'external_id', 'code' => 'validation_failed'];
        }

        foreach (['external_code' => 50, 'external_version' => 64] as $field => $max) {
            if (isset($ack[$field]) && (! is_string($ack[$field]) || mb_strlen($ack[$field]) > $max)) {
                $errors[] = ['field' => $field, 'code' => 'validation_failed'];
            }
        }

        return $errors;
    }

    /**
     * @return array{code?: string, message?: string, fields?: array<string, mixed>}
     */
    protected function errorPayload(mixed $error): array
    {
        if (! is_array($error)) {
            return [];
        }

        return array_filter([
            'code' => is_string($error['code'] ?? null) ? Str::limit($error['code'], 60, '') : null,
            'message' => is_string($error['message'] ?? null) ? Str::limit($error['message'], 250) : null,
            'fields' => is_array($error['fields'] ?? null) ? $error['fields'] : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    protected function rejected(array $base, ?string $field, string $code): array
    {
        return [...$base, 'result' => 'rejected', 'errors' => [['field' => $field, 'code' => $code]]];
    }
}
