<?php

namespace App\Actions\Offers;

use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Enums\StockBand;
use App\Models\Offer;
use App\Models\OfferLineSelection;
use App\Models\OfferSelectionEvent;
use App\Models\OfferVersion;
use App\Models\User;
use App\Services\Offers\Delivery\OfferDelivery;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use App\Services\Offers\OfferImageSnapshot;
use App\Services\Offers\OfferSnapshot;
use App\Services\Offers\OfferValidator;
use Illuminate\Support\Facades\DB;

/**
 * The explicit "send" (Spec 05 6.1): draft (no approval needed) or approved (unchanged since
 * approval) -> sent. Freezes option letters and stock bands, writes the immutable published
 * snapshot with its hash, enforces one open version per root, supersedes previous versions and
 * carries selections over by stable key (BR-19). Delivery is a stub (OfferDelivery).
 */
class SendOffer
{
    public function __construct(
        protected TransitionOffer $transition,
        protected RecalculateOffer $recalculate,
        protected OfferValidator $validator,
        protected DiscountRules $rules,
        protected OfferSnapshot $snapshot,
        protected OfferDelivery $delivery,
    ) {}

    public function handle(Offer $offer, User $user, SelectionChannel $channel): Offer
    {
        if ($offer->status === OfferStatus::Draft && $this->rules->requiresApproval($offer)) {
            throw new OfferActionException('approval_required');
        }

        if ($offer->status === OfferStatus::Approved && $offer->approved_hash !== $offer->content_hash) {
            throw new OfferActionException('content_changed');
        }

        if (! in_array($offer->status, [OfferStatus::Draft, OfferStatus::Approved], true)) {
            throw new OfferActionException('invalid_transition', ['from' => $offer->status->label(), 'to' => OfferStatus::Sent->label()]);
        }

        if (! in_array($channel, SelectionChannel::sending(), true)) {
            throw new OfferActionException('invalid_channel');
        }

        $sent = DB::transaction(function () use ($offer, $user, $channel): Offer {
            // One open version per root (6.1): lock the root row while checking.
            Offer::query()->whereKey($offer->rootId())->lockForUpdate()->first();

            $this->refreshStock($offer);
            $this->recalculate->handle($offer);

            if (OfferValidator::hasBlockers($this->validator->validate($offer))) {
                throw new OfferActionException('validation_blockers');
            }

            $previous = Offer::query()
                ->where(fn ($query) => $query->whereKey($offer->rootId())->orWhere('root_offer_id', $offer->rootId()))
                ->whereKeyNot($offer->id)
                ->get();

            foreach ($previous as $other) {
                if ($other->status->isOpen() || $other->status === OfferStatus::Expired) {
                    $this->transition->handle($other, OfferStatus::Superseded, $user, 'new_version_sent', ['superseded_by_id' => $offer->id]);
                } elseif ($other->status === OfferStatus::Rejected && $other->superseded_by_id === null) {
                    $other->forceFill(['superseded_by_id' => $offer->id])->save();
                }
            }

            // Spec 05 section 16: freeze the line pictures as sent.
            app(OfferImageSnapshot::class)->snapshot($offer);

            $this->transition->handle($offer, OfferStatus::Sent, $user, null, ['sent_at' => now(), 'sent_by' => $user->id, 'sent_channel' => $channel]);

            OfferVersion::query()->create([
                'offer_id' => $offer->id,
                'root_offer_id' => $offer->rootId(),
                'version' => $offer->version,
                'content_hash' => $offer->content_hash,
                'payload' => $this->snapshot->payload($offer),
                'sent_at' => now(),
                'sent_by' => $user->id,
                'channel' => $channel->value,
                'document_locale' => $offer->preferredDocumentLocale()?->value,
                'created_at' => now(),
            ]);

            if ($offer->previous_version_id !== null && $this->carryOver($offer, $user)) {
                $this->recalculate->handle($offer);
                $this->transition->handle($offer, OfferStatus::PartiallySelected, $user, 'selections_carried_over');
            }

            return $offer;
        });

        $this->delivery->deliver($sent, $channel, $user);

        return $sent;
    }

    protected function refreshStock(Offer $offer): void
    {
        foreach ($offer->lines()->with('item.stocks')->get() as $line) {
            if ($line->item === null) {
                continue;
            }

            $line->forceFill([
                'cost_price_minor' => $line->item->costBasis($offer->currency)?->amount ?? $line->cost_price_minor,
                'stock_band' => StockBand::for($line->item, (string) $line->quantity),
                'stock_available_qty' => $line->item->stocks->isEmpty() ? null : $line->item->availableStock(),
                'stock_checked_at' => now(),
            ])->save();
        }
    }

    /**
     * BR-19: a previous selection is kept when a line with the same stable key exists in a group
     * with the same stable key and the item is unchanged; otherwise it is dropped. Groups then
     * above max_select are cleared. Returns whether anything was carried over.
     */
    protected function carryOver(Offer $offer, User $user): bool
    {
        $previous = Offer::query()->with(['selections.line', 'groups'])->find($offer->previous_version_id);

        if ($previous === null || $previous->selections->isEmpty()) {
            return false;
        }

        $offer->load(['lines', 'groups']);
        $carried = [];

        foreach ($previous->selections as $selection) {
            $oldGroup = $previous->groups->firstWhere('id', $selection->option_group_id);
            $newGroup = $oldGroup === null ? null : $offer->groups->firstWhere('stable_key', $oldGroup->stable_key);
            $newLine = $newGroup === null ? null : $offer->lines->first(fn ($line) => $line->option_group_id === $newGroup->id
                && $line->stable_key === $selection->line?->stable_key
                && $line->item_id === $selection->line?->item_id);

            $event = [
                'offer_id' => $offer->id,
                'actor' => OfferLineSelection::ACTOR_CARRY_OVER,
                'user_id' => $user->id,
                'channel' => $selection->channel,
                'selection_revision' => $offer->selection_revision,
                'created_at' => now(),
            ];

            if ($newLine === null) {
                OfferSelectionEvent::query()->create([...$event, 'option_group_id' => $newGroup?->id, 'offer_line_id' => null, 'event' => 'carry_over_dropped', 'note' => $selection->line?->sku]);

                continue;
            }

            $carried[$newGroup->id][] = $newLine->id;
        }

        foreach ($carried as $groupId => $lineIds) {
            $group = $offer->groups->firstWhere('id', $groupId);

            if (count($lineIds) > $group->max_select) {
                OfferSelectionEvent::query()->create(['offer_id' => $offer->id, 'option_group_id' => $groupId, 'event' => 'group_cleared', 'actor' => OfferLineSelection::ACTOR_CARRY_OVER, 'user_id' => $user->id, 'selection_revision' => $offer->selection_revision, 'created_at' => now()]);
                unset($carried[$groupId]);

                continue;
            }

            foreach ($lineIds as $lineId) {
                $old = $previous->selections->first(fn ($selection) => $selection->line?->stable_key === $offer->lines->firstWhere('id', $lineId)->stable_key);

                OfferLineSelection::query()->create([
                    'offer_id' => $offer->id,
                    'option_group_id' => $groupId,
                    'offer_line_id' => $lineId,
                    'selected_by_actor' => OfferLineSelection::ACTOR_CARRY_OVER,
                    'selected_by_user_id' => $user->id,
                    'channel' => $old?->channel ?? SelectionChannel::Phone,
                    'selected_at' => now(),
                ]);

                OfferSelectionEvent::query()->create(['offer_id' => $offer->id, 'option_group_id' => $groupId, 'offer_line_id' => $lineId, 'event' => 'carried_over', 'actor' => OfferLineSelection::ACTOR_CARRY_OVER, 'user_id' => $user->id, 'channel' => $old?->channel, 'selection_revision' => $offer->selection_revision, 'created_at' => now()]);
            }
        }

        return $carried !== [];
    }
}
