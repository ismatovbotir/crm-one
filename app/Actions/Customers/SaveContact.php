<?php

namespace App\Actions\Customers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a contact. Setting a contact primary unsets the previous primary of the same
 * customer in the same transaction (Spec 01 BR-7), so at most one primary exists.
 */
class SaveContact
{
    /**
     * @param  array{first_name: string, last_name?: string|null, position?: string|null, email?: string|null, phone?: string|null, is_primary?: bool, notes?: string|null}  $attributes
     */
    public function handle(Partner $customer, array $attributes, ?Contact $contact = null): Contact
    {
        return DB::transaction(function () use ($customer, $attributes, $contact): Contact {
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $makePrimary = (bool) ($attributes['is_primary'] ?? false);
            $wasPrimary = (bool) $contact?->is_primary;

            if ($makePrimary) {
                $customer->contacts()
                    ->where('is_primary', true)
                    ->when($contact !== null, fn ($query) => $query->whereKeyNot($contact->id))
                    ->get()
                    ->each(fn (Contact $previous) => $previous->update(['is_primary' => false]));
            }

            $contact ??= new Contact(['partner_id' => $customer->id]);
            $contact->fill([...$attributes, 'is_primary' => $makePrimary]);
            $contact->partner_id = $customer->id;
            $contact->save();

            if ($makePrimary && ! $wasPrimary) {
                $customer->logActivity('primary_contact_changed', ['new' => ['contact' => $contact->fullName()]]);
            }

            return $contact;
        });
    }
}
