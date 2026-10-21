<?php

namespace App\Actions\Offers;

use App\Enums\OptionGroupKind;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\User;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Alternative and optional groups of a draft (Spec 05 US-2..US-4, AC-5): add, edit title and
 * min/max, remove, convert a fixed line into option A of a new alternative group ("Offer
 * alternatives"), and ungroup a group with one line left back into a fixed line.
 */
class ManageOptionGroups
{
    public function __construct(protected BeginOfferEdit $begin, protected RecalculateOffer $recalculate) {}

    public function add(Offer $offer, OptionGroupKind $kind, string $title, User $user): OfferOptionGroup
    {
        if (! $kind->isGroup()) {
            throw new OfferActionException('invalid_group_kind');
        }

        $this->begin->handle($offer, $user);

        return DB::transaction(function () use ($offer, $kind, $title): OfferOptionGroup {
            $group = OfferOptionGroup::query()->create([
                'offer_id' => $offer->id,
                'stable_key' => (string) Str::uuid(),
                'position' => MoveOfferBlock::nextBlockPosition($offer),
                'kind' => $kind,
                'title' => trim($title) !== '' ? mb_substr(trim($title), 0, 255) : __('offers.builder.new_group_title.'.$kind->value),
                'min_select' => $kind->defaultMin(),
                'max_select' => $kind->defaultMax(0),
            ]);

            $offer->logActivity('group_added', ['kind' => $kind->value, 'title' => $group->title]);
            $this->recalculate->handle($offer);

            return $group;
        });
    }

    /**
     * @param  array{title?: string, description?: string|null, min_select?: int, max_select?: int}  $data
     */
    public function update(OfferOptionGroup $group, array $data, User $user): OfferOptionGroup
    {
        $offer = $group->offer;
        $this->begin->handle($offer, $user);
        $count = $group->lines()->count();

        $min = (int) ($data['min_select'] ?? $group->min_select);
        $max = (int) ($data['max_select'] ?? $group->max_select);

        if ($min < 0 || $max < 1 || $min > $max || $max > max(1, $count)) {
            throw new OfferActionException('min_max_invalid', ['count' => $count]);
        }

        return DB::transaction(function () use ($group, $data, $min, $max, $offer): OfferOptionGroup {
            $group->fill([
                'title' => isset($data['title']) && trim($data['title']) !== '' ? mb_substr(trim($data['title']), 0, 255) : $group->title,
                'description' => array_key_exists('description', $data) ? ($data['description'] ?: null) : $group->description,
                'min_select' => $min,
                'max_select' => $max,
            ]);

            if ($group->isDirty()) {
                $group->save();
                $offer->logActivity('group_updated', ['title' => $group->title]);
                $this->recalculate->handle($offer);
            }

            return $group;
        });
    }

    public function remove(OfferOptionGroup $group, User $user): void
    {
        $offer = $group->offer;
        $this->begin->handle($offer, $user);

        DB::transaction(function () use ($group, $offer): void {
            $title = $group->title;
            $group->lines()->delete();
            $group->delete();
            $offer->logActivity('group_removed', ['title' => $title]);
            $this->recalculate->handle($offer);
        });
    }

    /**
     * "Offer alternatives": the fixed line becomes option A of a new alternative group at the
     * same place, keeping its stable key and price (AC-5).
     */
    public function convertToAlternatives(OfferLine $line, User $user): OfferOptionGroup
    {
        if (! $line->isFixed()) {
            throw new OfferActionException('already_grouped');
        }

        $offer = $line->offer;
        $this->begin->handle($offer, $user);

        return DB::transaction(function () use ($line, $offer): OfferOptionGroup {
            $group = OfferOptionGroup::query()->create([
                'offer_id' => $offer->id,
                'stable_key' => (string) Str::uuid(),
                'position' => $line->position,
                'kind' => OptionGroupKind::Alternative,
                'title' => $line->name,
                'min_select' => 1,
                'max_select' => 1,
            ]);

            $line->forceFill(['option_group_id' => $group->id, 'position' => 1, 'is_recommended' => true])->save();
            $offer->logActivity('group_added', ['kind' => OptionGroupKind::Alternative->value, 'title' => $group->title]);
            $this->recalculate->handle($offer);

            return $group;
        });
    }

    /**
     * Group with a single line left becomes a fixed line again (AC-5).
     */
    public function ungroup(OfferOptionGroup $group, User $user): OfferLine
    {
        $lines = $group->lines()->get();

        if ($lines->count() !== 1) {
            throw new OfferActionException('ungroup_needs_one');
        }

        $offer = $group->offer;
        $this->begin->handle($offer, $user);

        return DB::transaction(function () use ($group, $lines, $offer): OfferLine {
            $line = $lines->first();
            $line->forceFill(['option_group_id' => null, 'position' => $group->position, 'is_recommended' => false, 'option_label' => null])->save();
            $group->delete();
            $offer->logActivity('group_removed', ['title' => $group->title]);
            $this->recalculate->handle($offer);

            return $line;
        });
    }
}
