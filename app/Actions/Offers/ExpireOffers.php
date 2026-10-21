<?php

namespace App\Actions\Offers;

use App\Enums\OfferStatus;
use App\Models\Offer;
use Carbon\CarbonImmutable;

/**
 * Scheduler job `offers:expire` (Spec 05 6.1, AC-17): open offers whose inclusive valid_until
 * is before today in the offers timezone become expired. Idempotent.
 */
class ExpireOffers
{
    public function __construct(protected TransitionOffer $transition) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone((string) config('offers.timezone'))->toDateString();
        $expired = 0;

        Offer::query()
            ->whereIn('status', OfferStatus::open())
            ->whereDate('valid_until', '<', $today)
            ->each(function (Offer $offer) use (&$expired): void {
                $this->transition->handle($offer, OfferStatus::Expired, null, 'validity_passed', ['expired_at' => now()]);
                $expired++;
            });

        return $expired;
    }
}
