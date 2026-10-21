<?php

namespace App\Actions\Offers;

use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keyboard-accessible reordering (Spec 05 10.2): move a block (fixed line or group) or an option
 * inside its group one step up or down.
 */
class MoveOfferBlock
{
    public function __construct(protected BeginOfferEdit $begin, protected RecalculateOffer $recalculate) {}

    public static function nextBlockPosition(Offer $offer): int
    {
        return max(
            (int) OfferLine::query()->where('offer_id', $offer->id)->whereNull('option_group_id')->max('position'),
            (int) OfferOptionGroup::query()->where('offer_id', $offer->id)->max('position'),
        ) + 1;
    }

    /**
     * @param  'line'|'group'  $type
     */
    public function block(Offer $offer, string $type, int $id, int $direction, User $user): void
    {
        $this->begin->handle($offer, $user);
        $offer->load(['lines', 'groups']);
        $blocks = $offer->blocks()->values();
        $index = $blocks->search(fn (array $block): bool => $block['type'] === $type && ($type === 'line' ? $block['line']->id : $block['group']->id) === $id);
        $target = $index === false ? false : $index + ($direction < 0 ? -1 : 1);

        if ($index === false || $target < 0 || $target >= $blocks->count()) {
            return;
        }

        DB::transaction(function () use ($blocks, $index, $target, $offer): void {
            $order = $blocks->all();
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            foreach (array_values($order) as $position => $block) {
                $model = $block['type'] === 'line' ? $block['line'] : $block['group'];
                $model->forceFill(['position' => $position + 1])->save();
            }

            $this->recalculate->handle($offer);
        });
    }

    public function option(OfferLine $line, int $direction, User $user): void
    {
        $offer = $line->offer;
        $this->begin->handle($offer, $user);

        $siblings = OfferLine::query()->where('option_group_id', $line->option_group_id)->orderBy('position')->orderBy('id')->get()->values();
        $index = $siblings->search(fn (OfferLine $sibling): bool => $sibling->id === $line->id);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($line->option_group_id === null || $target < 0 || $target >= $siblings->count()) {
            return;
        }

        DB::transaction(function () use ($siblings, $index, $target, $offer): void {
            $order = $siblings->all();
            [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

            foreach (array_values($order) as $position => $sibling) {
                $sibling->forceFill(['position' => $position + 1])->save();
            }

            $this->recalculate->handle($offer);
        });
    }
}
