<?php

namespace App\Actions\Offers;

use App\Actions\Orders\CreateOrderFromOffer;
use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Models\Offer;
use App\Models\OfferLineSelection;
use App\Models\OfferSelectionEvent;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records the customer's acceptance on their behalf (Spec 05 BR-14/BR-15): open, not expired,
 * every group within min/max, no fixed or selected line whose item was deleted in 1C. Stores
 * who/when/how, the content hash and the accepted selection snapshot (group stable key ->
 * options) and appends `accepted_with` events. Once the acceptance has committed, the order is
 * created from OfferToOrderPayload (Spec 06 DEC-1); if that fails the acceptance stands and the
 * offer page offers "Create order" (idempotent).
 */
class AcceptOffer
{
    public function __construct(protected TransitionOffer $transition, protected CreateOrderFromOffer $createOrder) {}

    public function handle(Offer $offer, User $user, SelectionChannel $channel, ?string $note = null, ?string $evidence = null): Offer
    {
        // Staff record on the customer's behalf through internal channels; a portal user acts
        // only through the portal channel (Spec 03 BR-P.4).
        if ($user->isPortal() ? $channel !== SelectionChannel::Portal : ! in_array($channel, SelectionChannel::internal(), true)) {
            throw new OfferActionException('invalid_channel');
        }

        if ($channel->requiresNote() && blank($note)) {
            throw new OfferActionException('note_required', ['channel' => $channel->label()]);
        }

        if ($channel->requiresEvidence() && blank($evidence)) {
            throw new OfferActionException('evidence_required', ['channel' => $channel->label()]);
        }

        $accepted = DB::transaction(function () use ($offer, $user, $channel, $note, $evidence): Offer {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            RecordCustomerSelection::assertSelectable($offer);

            $missing = RecordCustomerSelection::missingGroups($offer);

            if ($missing !== []) {
                throw new OfferActionException('choices_missing', ['groups' => implode('; ', $missing)]);
            }

            $offer->load(['lines.item', 'groups', 'selections']);
            $selectedIds = $offer->selectedLineIds();
            $included = $offer->lines->filter(fn ($line) => $line->option_group_id === null || in_array($line->id, $selectedIds, true));
            $blocked = $included->filter(fn ($line) => $line->item === null || $line->item->isDeletedInOneC() || $line->item->trashed());

            if ($blocked->isNotEmpty()) {
                throw new OfferActionException('item_unavailable', ['items' => $blocked->pluck('name')->implode(', ')]);
            }

            $snapshot = [];

            foreach ($offer->blocks()->where('type', 'group') as $block) {
                $snapshot[$block['group']->stable_key] = [
                    'title' => $block['group']->title,
                    'number' => $block['number'],
                    'lines' => $block['lines']->filter(fn ($line) => in_array($line->id, $selectedIds, true))
                        ->map(fn ($line) => ['stable_key' => $line->stable_key, 'label' => $block['number'].$line->option_label, 'offer_line_id' => $line->id])
                        ->values()->all(),
                ];
            }

            foreach ($offer->selections as $selection) {
                OfferSelectionEvent::query()->create([
                    'offer_id' => $offer->id, 'option_group_id' => $selection->option_group_id, 'offer_line_id' => $selection->offer_line_id,
                    'event' => 'accepted_with', 'actor' => $user->isPortal() ? OfferLineSelection::ACTOR_CUSTOMER : OfferLineSelection::ACTOR_INTERNAL, 'user_id' => $user->id, 'channel' => $channel,
                    'note' => $note, 'evidence_note' => $evidence, 'selection_revision' => $offer->selection_revision, 'created_at' => now(),
                ]);
            }

            return $this->transition->handle($offer, OfferStatus::Accepted, $user, $note, [
                'accepted_at' => now(),
                'accepted_by_user_id' => $user->id,
                'accepted_via' => $channel,
                'acceptance_note' => trim(implode("\n", array_filter([$note, $evidence]))) ?: null,
                'accepted_hash' => $offer->content_hash,
                'accepted_selection' => $snapshot,
            ]);
        });

        try {
            $this->createOrder->handle($accepted, $user);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $accepted;
    }
}
