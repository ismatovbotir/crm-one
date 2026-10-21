<?php

namespace App\Services\Offers\Delivery;

use App\Enums\SelectionChannel;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Stub delivery: records that delivery was requested (no e-mail/Telegram is sent). Only ids are
 * logged, never customer data.
 */
class LogOfferDelivery implements OfferDelivery
{
    public function deliver(Offer $offer, SelectionChannel $channel, User $sender): void
    {
        Log::info('Offer delivery requested (stub, nothing sent).', [
            'offer_id' => $offer->id,
            'channel' => $channel->value,
            'sender_id' => $sender->id,
        ]);
    }
}
