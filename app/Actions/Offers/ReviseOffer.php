<?php

namespace App\Actions\Offers;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Facades\DB;

/**
 * Creates the next version as a draft copy (Spec 05 BR-18): same number, version + 1, groups and
 * lines copied with the same stable keys. The previous version keeps its status until the new
 * one is sent (AC-24); a second "revise" returns the existing draft. Prices are kept as
 * snapshotted (re-pricing against the current item sale price is a later increment, BR-21).
 */
class ReviseOffer
{
    public function __construct(protected RecalculateOffer $recalculate) {}

    public function handle(Offer $offer, User $user): Offer
    {
        if (! $offer->status->isRevisable()) {
            throw new OfferActionException('not_revisable', ['status' => $offer->status->label()]);
        }

        $existing = Offer::query()
            ->where(fn ($query) => $query->whereKey($offer->rootId())->orWhere('root_offer_id', $offer->rootId()))
            ->whereIn('status', [OfferStatus::Draft, OfferStatus::PendingApproval, OfferStatus::Approved])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($offer, $user): Offer {
            $offer->load(['groups', 'lines']);
            $nextVersion = (int) Offer::withTrashed()->where(fn ($query) => $query->whereKey($offer->rootId())->orWhere('root_offer_id', $offer->rootId()))->max('version') + 1;

            $copy = Offer::query()->create([
                ...$offer->only([
                    'number', 'lead_id', 'customer_id', 'contact_id', 'currency', 'prices_include_vat', 'payment_terms_days',
                    'payment_terms_text', 'delivery_terms_text', 'warranty_text', 'intro_text', 'customer_notes', 'internal_notes', 'document_locale', 'requisition_id',
                ]),
                'valid_until' => $offer->isExpiredByDate() ? now((string) config('offers.timezone'))->addDays((int) config('offers.default_validity_days', 14))->toDateString() : $offer->valid_until->toDateString(),
                'version' => $nextVersion,
                'root_offer_id' => $offer->rootId(),
                'previous_version_id' => $offer->id,
                'status' => OfferStatus::Draft,
                'owner_id' => $offer->owner_id,
            ]);

            $groupMap = [];

            foreach ($offer->groups as $group) {
                $groupMap[$group->id] = OfferOptionGroup::query()->create([
                    ...$group->only(['stable_key', 'position', 'kind', 'title', 'description', 'min_select', 'max_select']),
                    'offer_id' => $copy->id,
                ])->id;
            }

            foreach ($offer->lines as $line) {
                $attributes = collect($line->getAttributes())->except(['id', 'offer_id', 'option_group_id', 'created_at', 'updated_at'])->all();
                (new OfferLine)->forceFill([...$attributes, 'offer_id' => $copy->id, 'option_group_id' => $line->option_group_id === null ? null : $groupMap[$line->option_group_id]])->save();
            }

            TransitionOffer::recordCreation($copy, $user, 'revision_of:'.$offer->displayNumber());
            $copy->logActivity('revised_from', ['version' => $offer->version]);
            $this->recalculate->handle($copy);

            return $copy;
        });
    }
}
