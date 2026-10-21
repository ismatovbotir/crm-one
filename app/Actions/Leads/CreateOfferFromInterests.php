<?php

namespace App\Actions\Leads;

use App\Actions\Offers\AddOfferLine;
use App\Actions\Offers\CreateOffer;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadItem;
use App\Models\Offer;
use App\Models\Partner;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Collection;

/**
 * Draft offer with the lead's product interests as fixed lines (Spec 04 BR-6 and section 18): used
 * by "Create offer" on a qualified lead, by the "Create a first offer" option of "Convert to
 * customer" and by "Create offer from interests" on the customer card. Items that can no longer be
 * offered are skipped and reported; prices always come from the catalogue at that moment.
 */
class CreateOfferFromInterests
{
    public function __construct(protected CreateOffer $createOffer, protected AddOfferLine $addLine) {}

    /**
     * @param  Collection<int, LeadItem>  $interests
     * @return array{offer: Offer, skipped: list<string>}
     */
    public function handle(User $user, Partner $customer, ?Contact $contact, Collection $interests, ?Lead $lead = null): array
    {
        $offer = $this->createOffer->handle($user, $customer, $contact, ['lead_id' => $lead?->id]);
        $skipped = [];

        foreach ($interests as $interest) {
            $interest->loadMissing('item.unit');

            if ($interest->item === null) {
                continue;
            }

            try {
                $this->addLine->handle($offer->refresh(), $interest->item, (string) $interest->quantity, $user);
            } catch (OfferActionException $exception) {
                $skipped[] = $interest->item->name.': '.$exception->getMessage();
            }
        }

        return ['offer' => $offer->refresh(), 'skipped' => $skipped];
    }

    /**
     * The customer's product interests from its leads, one row per item (latest lead wins).
     *
     * @return Collection<int, LeadItem>
     */
    public static function interestsOf(Partner $customer): Collection
    {
        return LeadItem::query()->with(['item.unit', 'lead:id,number,status,created_at'])
            ->whereHas('lead', fn ($query) => $query->where('customer_id', $customer->id))
            ->orderByDesc('lead_id')->orderBy('id')->get()
            ->unique('item_id')->values();
    }
}
