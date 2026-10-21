<?php

namespace App\Actions\Offers;

use App\Enums\OptionGroupKind;
use App\Models\OfferLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a line from a draft (fixed line or option). An empty group stays until removed.
 */
class RemoveOfferLine
{
    public function __construct(protected BeginOfferEdit $begin, protected RecalculateOffer $recalculate) {}

    public function handle(OfferLine $line, User $user): void
    {
        $offer = $line->offer;
        $this->begin->handle($offer, $user);

        DB::transaction(function () use ($line, $offer): void {
            $group = $line->group;
            $line->delete();

            if ($group !== null) {
                $remaining = $group->lines()->count();

                if ($group->kind === OptionGroupKind::Optional && $group->max_select > $remaining) {
                    $group->forceFill(['max_select' => max(1, $remaining)])->save();
                }

                if ($group->kind === OptionGroupKind::Alternative && $group->min_select > max(1, $remaining)) {
                    $group->forceFill(['min_select' => 1, 'max_select' => 1])->save();
                }
            }

            $offer->logActivity('line_removed', ['sku' => $line->sku, 'name' => $line->name]);
            $this->recalculate->handle($offer);
        });
    }
}
