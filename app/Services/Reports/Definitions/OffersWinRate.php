<?php

namespace App\Services\Reports\Definitions;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\User;
use App\Services\Reports\Report;

/**
 * Offer win rate by owner and currency: offers sent in the period, accepted, rejected, expired,
 * win rate (accepted / decided) and the accepted value per currency (never converted).
 */
class OffersWinRate extends Report
{
    public function key(): string
    {
        return 'offers_win_rate';
    }

    public function allowed(User $user): bool
    {
        return $user->can('viewAny', Offer::class);
    }

    public function columns(): array
    {
        return [
            ['key' => 'owner', 'type' => 'text'], ['key' => 'sent', 'type' => 'int'], ['key' => 'accepted', 'type' => 'int'], ['key' => 'rejected', 'type' => 'int'],
            ['key' => 'expired', 'type' => 'int'], ['key' => 'win_rate', 'type' => 'percent'], ['key' => 'accepted_value', 'type' => 'money'],
        ];
    }

    public function rows(User $user, array $filters): iterable
    {
        $offers = Offer::query()->visibleTo($user)->with('owner:id,name')->whereNotNull('sent_at')
            ->when($filters['from'], fn ($q, $from) => $q->whereDate('sent_at', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->whereDate('sent_at', '<=', $to))
            ->get(['id', 'owner_id', 'status', 'currency', 'selected_gross_minor', 'fixed_gross_minor']);

        foreach ($offers->groupBy(fn (Offer $offer): string => $offer->owner_id.'|'.$offer->currency) as $group) {
            $accepted = $group->where('status', OfferStatus::Accepted);
            $decided = $group->whereIn('status', [OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired])->count();

            yield [
                'owner' => (string) $group->first()->owner?->name,
                'sent' => $group->count(),
                'accepted' => $accepted->count(),
                'rejected' => $group->where('status', OfferStatus::Rejected)->count(),
                'expired' => $group->where('status', OfferStatus::Expired)->count(),
                'win_rate' => $decided > 0 ? round(100 * $accepted->count() / $decided, 1) : null,
                'accepted_value' => ['minor' => (int) $accepted->sum(fn (Offer $offer): int => (int) ($offer->selected_gross_minor ?? $offer->fixed_gross_minor)), 'currency' => $group->first()->currency],
            ];
        }
    }
}
