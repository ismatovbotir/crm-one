<?php

namespace App\Services\Offers\Delivery;

use App\Enums\SelectionChannel;
use App\Models\Offer;
use App\Models\User;

/**
 * Delivers a sent offer to the customer (email with PDF, Telegram, ...). Out of scope for now:
 * the bound implementation is a stub. Marking an offer "sent" happens only in SendOffer.
 */
interface OfferDelivery
{
    public function deliver(Offer $offer, SelectionChannel $channel, User $sender): void;
}
