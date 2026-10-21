<?php

namespace App\Actions\Offers;

use App\Enums\OfferStatus;
use App\Enums\SelectionChannel;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferLineSelection;
use App\Models\OfferSelectionEvent;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Facades\DB;

/**
 * A sales rep records the customer's choice on their behalf (Spec 05 BR-10..BR-12, US-11):
 * open, not expired, channel required, note/evidence per channel, at most max_select per group
 * (min is checked at acceptance), optimistic lock on selection_revision, current state +
 * append-only events, totals recomputed, sent/viewed -> partially_selected.
 */
class RecordCustomerSelection
{
    public function __construct(protected TransitionOffer $transition, protected RecalculateOffer $recalculate) {}

    /**
     * @param  array<int, list<int>>  $choices  group id => selected line ids (groups not listed are untouched)
     */
    public function handle(Offer $offer, User $user, array $choices, SelectionChannel $channel, ?string $note = null, ?string $evidence = null, ?int $expectedRevision = null): Offer
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

        return DB::transaction(function () use ($offer, $user, $choices, $channel, $note, $evidence, $expectedRevision): Offer {
            $offer = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $this->assertSelectable($offer);

            if ($expectedRevision !== null && $expectedRevision !== $offer->selection_revision) {
                throw new OfferActionException('selection_conflict');
            }

            $offer->load(['groups', 'lines']);
            $current = $offer->selections()->get()->groupBy('option_group_id');
            $revision = $offer->selection_revision + 1;
            $changed = false;

            foreach ($choices as $groupId => $lineIds) {
                $group = $offer->groups->firstWhere('id', (int) $groupId);

                if ($group === null) {
                    throw new OfferActionException('group_mismatch');
                }

                $lineIds = array_values(array_unique(array_map('intval', $lineIds)));
                $valid = $offer->lines->where('option_group_id', $group->id)->pluck('id')->all();

                if (array_diff($lineIds, $valid) !== []) {
                    throw new OfferActionException('line_mismatch');
                }

                if (count($lineIds) > $group->max_select) {
                    throw new OfferActionException('too_many_selected', ['group' => $group->title, 'max' => $group->max_select]);
                }

                $before = $current->get($group->id, collect())->pluck('offer_line_id')->map(fn (mixed $id): int => (int) $id)->all();
                $event = ['offer_id' => $offer->id, 'option_group_id' => $group->id, 'actor' => $user->isPortal() ? OfferLineSelection::ACTOR_CUSTOMER : OfferLineSelection::ACTOR_INTERNAL, 'user_id' => $user->id, 'channel' => $channel, 'note' => $note, 'evidence_note' => $evidence, 'selection_revision' => $revision, 'created_at' => now()];

                foreach (array_diff($before, $lineIds) as $removed) {
                    OfferLineSelection::query()->where('offer_line_id', $removed)->delete();
                    OfferSelectionEvent::query()->create([...$event, 'offer_line_id' => $removed, 'event' => 'deselected']);
                    $changed = true;
                }

                foreach (array_diff($lineIds, $before) as $added) {
                    OfferLineSelection::query()->create([
                        'offer_id' => $offer->id, 'option_group_id' => $group->id, 'offer_line_id' => $added,
                        'selected_by_actor' => $user->isPortal() ? OfferLineSelection::ACTOR_CUSTOMER : OfferLineSelection::ACTOR_INTERNAL, 'selected_by_user_id' => $user->id,
                        'channel' => $channel, 'selected_at' => now(),
                    ]);
                    OfferSelectionEvent::query()->create([...$event, 'offer_line_id' => $added, 'event' => 'selected']);
                    $changed = true;
                }
            }

            if (! $changed) {
                return $offer;
            }

            $offer->forceFill(['selection_revision' => $revision])->save();
            $this->recalculate->handle($offer);

            if (in_array($offer->status, [OfferStatus::Sent, OfferStatus::Viewed], true) && $offer->selections()->exists()) {
                $this->transition->handle($offer, OfferStatus::PartiallySelected, $user, 'selection_recorded');
            }

            return $offer;
        });
    }

    public static function assertSelectable(Offer $offer): void
    {
        if (! $offer->status->isOpen()) {
            throw new OfferActionException('not_open', ['status' => $offer->status->label()]);
        }

        if ($offer->isExpiredByDate()) {
            throw new OfferActionException('expired', ['date' => $offer->valid_until->toDateString()]);
        }
    }

    /**
     * Groups whose selection does not satisfy min/max yet (blocks acceptance, AC-13).
     *
     * @return list<string> group titles with their block number
     */
    public static function missingGroups(Offer $offer): array
    {
        $offer->load(['groups', 'lines']);
        $selected = $offer->selectedLineIds();
        $missing = [];

        foreach ($offer->blocks()->where('type', 'group') as $block) {
            $count = $block['lines']->filter(fn (OfferLine $line): bool => in_array($line->id, $selected, true))->count();

            if ($count < $block['group']->min_select || $count > $block['group']->max_select) {
                $missing[] = $block['number'].'. '.$block['group']->title;
            }
        }

        return $missing;
    }
}
