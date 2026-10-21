<?php

namespace App\Services\Offers;

use App\Authorization\PermissionMap;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\User;

/**
 * Discount limits and approval rules (Spec 05 4.3, DEC-6; rev. 4 cost basis). Limits come from
 * config/offers.php `discount_limits_bp` (grant => basis points); approval needs `offers.approve`.
 * The offer's worst case (`max_discount_bp`: discount + manual price below the item sale price,
 * every line of every group) is compared with the owner's limit. Any line sold below its cost
 * basis (stock cost price, else purchase price) needs approval by someone with
 * `offers.approve_below_cost`, whatever the discount. Nobody approves their own offer.
 */
class DiscountRules
{
    /** Widest discount (bp) the user may give without approval; 0 when none. */
    public function limitFor(User $user): int
    {
        $limit = 0;

        foreach ((array) config('offers.discount_limits_bp') as $grant => $bp) {
            if (PermissionMap::matchesGrant($user, (string) $grant)) {
                $limit = max($limit, (int) $bp);
            }
        }

        return $limit;
    }

    public function requiresApproval(Offer $offer): bool
    {
        return $offer->max_discount_bp > $this->limitFor($offer->owner) || $this->hasBelowCost($offer);
    }

    public function hasBelowCost(Offer $offer): bool
    {
        return $offer->lines->contains(fn (OfferLine $line): bool => $line->isBelowCost());
    }

    /**
     * Whether $user may approve the offer's current worst-case discount (and below-cost lines).
     */
    public function canApprove(User $user, Offer $offer): bool
    {
        return $user->id !== $offer->owner_id
            && PermissionMap::allowsFor($user, 'offers.approve', $offer->owner_id)
            && $this->limitFor($user) >= $offer->max_discount_bp
            && (! $this->hasBelowCost($offer) || PermissionMap::allowsFor($user, 'offers.approve_below_cost', $offer->owner_id));
    }

    /**
     * Lines that triggered approval (shown to approvers): discount above the owner's limit or
     * price below cost.
     *
     * @return list<array{line_id: int, label: string, name: string, discount_bp: int, below_cost: bool}>
     */
    public function reasons(Offer $offer): array
    {
        $limit = $this->limitFor($offer->owner);
        $reasons = [];

        foreach ($offer->blocks() as $block) {
            $lines = $block['type'] === 'line' ? [$block['line']] : $block['lines']->all();

            foreach ($lines as $line) {
                if ($line->effective_discount_bp > $limit || $line->isBelowCost()) {
                    $reasons[] = [
                        'line_id' => $line->id,
                        'label' => $block['number'].($line->option_label ?? ''),
                        'name' => $line->name,
                        'discount_bp' => $line->effective_discount_bp,
                        'below_cost' => $line->isBelowCost(),
                    ];
                }
            }
        }

        return $reasons;
    }
}
