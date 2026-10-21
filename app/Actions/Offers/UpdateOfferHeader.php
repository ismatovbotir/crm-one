<?php

namespace App\Actions\Offers;

use App\Enums\Locale;
use App\Models\Contact;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Header fields of a draft (Spec 05 10.2): contact, validity, terms, texts, internal notes,
 * document language. Currency can change only while the offer has no lines (prices were
 * resolved in it).
 */
class UpdateOfferHeader
{
    public function __construct(protected BeginOfferEdit $begin, protected RecalculateOffer $recalculate) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function handle(Offer $offer, User $user, array $data): Offer
    {
        $this->begin->handle($offer, $user);

        if (array_key_exists('contact_id', $data) && $data['contact_id'] !== null
            && ! Contact::query()->whereKey($data['contact_id'])->where('partner_id', $offer->customer_id)->exists()) {
            throw new OfferActionException('contact_mismatch');
        }

        if (isset($data['currency']) && strtoupper($data['currency']) !== $offer->currency && $offer->lines()->exists()) {
            throw new OfferActionException('currency_locked');
        }

        if (isset($data['valid_until'])) {
            $timezone = (string) config('offers.timezone');
            $date = CarbonImmutable::parse($data['valid_until'])->toDateString();
            $today = CarbonImmutable::now($timezone)->toDateString();
            $max = CarbonImmutable::now($timezone)->addDays((int) config('offers.max_validity_days', 90))->toDateString();

            if ($date < $today || $date > $max) {
                throw new OfferActionException('validity_range', ['max' => (int) config('offers.max_validity_days', 90)]);
            }
        }

        if (isset($data['document_locale']) && $data['document_locale'] !== null && Locale::fromAvailable($data['document_locale']) === null) {
            $data['document_locale'] = null;
        }

        return DB::transaction(function () use ($offer, $data): Offer {
            $offer->fill(array_intersect_key($data, array_flip([
                'contact_id', 'currency', 'valid_until', 'payment_terms_days', 'payment_terms_text', 'delivery_terms_text',
                'warranty_text', 'intro_text', 'customer_notes', 'internal_notes', 'document_locale',
            ])));

            if ($offer->isDirty()) {
                $offer->save();
                $this->recalculate->handle($offer);
            }

            return $offer;
        });
    }
}
