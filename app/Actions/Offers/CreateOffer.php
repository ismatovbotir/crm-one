<?php

namespace App\Actions\Offers;

use App\Enums\OfferStatus;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\NumberSequence;
use App\Models\Offer;
use App\Models\Partner;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft offer for a customer (Spec 05 US-1; leads come with Spec 04, `lead_id` stays
 * null). Number OF-YYYY-NNNNN on first save; currency and payment terms prefilled from the
 * customer; VAT basis of the offer from config offers.prices_include_vat (item prices with the
 * other basis are converted once when a line is added).
 */
class CreateOffer
{
    /**
     * @param  array{currency?: string|null, valid_until?: string|null, lead_id?: int|null}  $data
     */
    public function handle(User $owner, Partner $customer, ?Contact $contact = null, array $data = []): Offer
    {
        if ($customer->trashed() || $customer->isDeletedInOneC()) {
            throw new OfferActionException('customer_unavailable');
        }

        if ($contact !== null && $contact->partner_id !== $customer->id) {
            throw new OfferActionException('contact_mismatch');
        }

        $currency = strtoupper((string) ($data['currency'] ?? $customer->currency ?? config('crm.base_currency')));
        $timezone = (string) config('offers.timezone');
        $validUntil = isset($data['valid_until']) && $data['valid_until'] !== null
            ? CarbonImmutable::parse($data['valid_until'])
            : CarbonImmutable::now($timezone)->addDays((int) config('offers.default_validity_days', 14));

        return DB::transaction(function () use ($owner, $customer, $contact, $data, $currency, $validUntil, $timezone): Offer {
            $year = (int) CarbonImmutable::now($timezone)->format('Y');
            $prefix = (string) config('offers.number_prefix', 'OF');

            $offer = Offer::query()->create([
                'number' => NumberSequence::format($prefix, $year, NumberSequence::next($prefix, $year)),
                'version' => 1,
                'status' => OfferStatus::Draft,
                'lead_id' => $data['lead_id'] ?? null,
                'customer_id' => $customer->id,
                'contact_id' => $contact?->id ?? $customer->primaryContact?->id,
                'owner_id' => $owner->id,
                'currency' => $currency,
                'prices_include_vat' => (bool) config('offers.prices_include_vat'),
                'valid_until' => $validUntil->toDateString(),
                'payment_terms_days' => $customer->payment_terms_days,
            ]);

            TransitionOffer::recordCreation($offer, $owner);
            app(RecalculateOffer::class)->handle($offer);

            return $offer;
        });
    }
}
