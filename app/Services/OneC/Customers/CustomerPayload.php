<?php

namespace App\Services\OneC\Customers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerOutboxEntry;
use App\Models\Partner;
use Carbon\CarbonInterface;

/**
 * Customer + contacts as returned by `GET /customers/pending` (Spec 02 section 10.1). Built at
 * poll time from the current state, so edits made before the poll collapse into one delivery.
 * Only shared fields are sent (BR-I.10).
 */
class CustomerPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forEntry(CustomerOutboxEntry $entry): array
    {
        $customer = $entry->customer;

        return [
            'outbox_id' => $entry->id,
            'queued_at' => $this->time($entry->queued_at),
            'reason' => $entry->reason,
            'delivery_count' => $entry->delivery_count,
            ...$this->customer($customer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customer(Partner $customer): array
    {
        $contacts = $customer->relationLoaded('contacts') ? $customer->contacts : $customer->contacts()->withTrashed()->get();

        return [
            'crm_id' => $customer->uuid,
            'external_id' => $customer->external_id,
            'external_version' => $customer->external_version,
            'legal_type' => $customer->legal_type->oneCValue(),
            'name' => $customer->name,
            'full_name' => $customer->legal_name,
            'tax_id' => $customer->tax_id,
            'country' => $customer->country,
            'city' => $customer->city,
            'address' => $customer->address,
            'postal_code' => $customer->postal_code,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'contacts' => $contacts
                // Archived contacts are sent only when 1C knows them (so it can mark them deleted).
                ->filter(fn (Contact $contact): bool => ! $contact->trashed() || $contact->isLinkedToOneC())
                ->map(fn (Contact $contact): array => [
                    'crm_id' => $contact->uuid,
                    'external_id' => $contact->external_id,
                    'first_name' => $contact->first_name,
                    'last_name' => $contact->last_name,
                    'position' => $contact->position,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'is_primary' => $contact->is_primary,
                    'is_deleted' => $contact->trashed(),
                ])
                ->values()
                ->all(),
        ];
    }

    public function time(?CarbonInterface $time): ?string
    {
        return $time?->copy()->setTimezone((string) config('onec.timezone'))->toIso8601String();
    }
}
