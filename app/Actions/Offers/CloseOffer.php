<?php

namespace App\Actions\Offers;

use App\Enums\OfferRejectReason;
use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;

/**
 * Ends an offer without acceptance: customer rejection recorded on behalf (reason code + text,
 * selections kept for analytics, BR-17) or cancellation/withdrawal by us (reason required).
 */
class CloseOffer
{
    public function __construct(protected TransitionOffer $transition) {}

    public function reject(Offer $offer, User $user, OfferRejectReason $reason, ?string $text = null): Offer
    {
        if (! $offer->status->isOpen()) {
            throw new OfferActionException('not_open', ['status' => $offer->status->label()]);
        }

        if ($reason === OfferRejectReason::Other && blank($text)) {
            throw new OfferActionException('reason_required');
        }

        return $this->transition->handle($offer, OfferStatus::Rejected, $user, filled($text) ? $text : 'reject:'.$reason->value, [
            'rejected_at' => now(),
            'rejected_by' => $user->id,
            'reject_reason_code' => $reason->value,
            'reject_reason_text' => $text,
        ]);
    }

    public function cancel(Offer $offer, User $user, string $reason): Offer
    {
        if (trim($reason) === '') {
            throw new OfferActionException('reason_required');
        }

        return $this->transition->handle($offer, OfferStatus::Cancelled, $user, $reason, [
            'cancelled_at' => now(),
            'cancelled_by' => $user->id,
            'cancel_reason' => $reason,
        ]);
    }
}
