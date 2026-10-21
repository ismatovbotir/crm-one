<?php

namespace App\Services\Timeline;

use App\Enums\AgreementStatus;
use App\Enums\LeadActivityType;
use App\Enums\LeadStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Agreement;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Partner;
use App\Models\StatusTransition;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Customer 360 timeline (roadmap step 5): changes of the customer record plus the status history of
 * its leads, offers, orders and agreements, newest first. Only records the user may see; each entry
 * links to its record.
 */
class CustomerTimeline
{
    public const LIMIT = 150;

    /**
     * @return Collection<int, array{at: mixed, type: string, label: string, detail: string|null, user: string|null, url: string|null}>
     */
    public function entries(Partner $customer, User $user): Collection
    {
        $entries = collect();

        if ($user->can('viewActivity', $customer)) {
            $entries = $entries->concat(ActivityLog::query()->with('causer:id,name')->where('subject_type', $customer->getMorphClass())->where('subject_id', $customer->id)
                ->latest('id')->limit(self::LIMIT)->get()
                ->map(fn (ActivityLog $row): array => ['at' => $row->created_at, 'type' => 'customer', 'label' => $row->eventLabel(), 'detail' => null, 'user' => $row->causer?->name, 'url' => null]));
        }

        $sources = [];

        if ($user->can('viewAny', Lead::class)) {
            $sources['lead'] = Lead::query()->visibleTo($user)->where('customer_id', $customer->id)->get(['id', 'uuid', 'number', 'status'])->keyBy('id');
        }

        if ($user->can('viewAny', Offer::class)) {
            $sources['offer'] = Offer::query()->visibleTo($user)->where('customer_id', $customer->id)->get(['id', 'number', 'version', 'status'])->keyBy('id');
        }

        if ($user->can('viewAny', Order::class)) {
            $sources['order'] = Order::query()->visibleTo($user)->where('customer_id', $customer->id)->get(['id', 'number', 'status', 'owner_id'])->keyBy('id');
        }

        if ($user->can('viewAny', Agreement::class)) {
            $sources['agreement'] = Agreement::query()->where('customer_id', $customer->id)->get()->filter(fn (Agreement $agreement): bool => $user->can('view', $agreement))->keyBy('id');
        }

        foreach ($sources as $type => $records) {
            if ($records->isEmpty()) {
                continue;
            }

            $transitions = StatusTransition::query()->with('user:id,name')->where('subject_type', $type)->whereIn('subject_id', $records->keys())->latest('id')->limit(self::LIMIT)->get();

            foreach ($transitions as $row) {
                $record = $records[$row->subject_id];
                $entries->push([
                    'at' => $row->created_at,
                    'type' => $type,
                    'label' => __('timeline.types.'.$type).' '.$this->number($record).': '.$this->statusLabel($type, $row->to_status),
                    'detail' => $row->reasonLabel(),
                    'user' => $row->user?->name,
                    'url' => route(match ($type) {
                        'lead' => 'leads.show',
                        'offer' => 'offers.show',
                        'order' => 'orders.show',
                        default => 'agreements.show',
                    }, $record),
                ]);
            }
        }

        // Spec 04 section 18: the lead's calls, notes and messages stay on the customer timeline.
        if (isset($sources['lead']) && $sources['lead']->isNotEmpty()) {
            $activities = ActivityLog::query()->with('causer:id,name')->where('subject_type', 'lead')->whereIn('subject_id', $sources['lead']->keys())
                ->whereIn('event', ['lead_activity', 'converted_to_customer'])->latest('id')->limit(self::LIMIT)->get();

            foreach ($activities as $row) {
                $record = $sources['lead'][$row->subject_id];
                $entries->push([
                    'at' => $row->created_at,
                    'type' => 'lead',
                    'label' => __('timeline.types.lead').' '.$record->number.': '.(LeadActivityType::tryFrom((string) ($row->properties['type'] ?? ''))?->label() ?? $row->eventLabel()),
                    'detail' => isset($row->properties['text']) ? mb_strimwidth((string) $row->properties['text'], 0, 300, '…') : null,
                    'user' => $row->causer?->name ?? ($row->properties['from'] ?? null),
                    'url' => route('leads.show', $record),
                ]);
            }
        }

        return $entries->sortByDesc(fn (array $entry): int => (int) $entry['at']?->getTimestamp())->take(self::LIMIT)->values();
    }

    protected function number(mixed $record): string
    {
        return $record instanceof Offer ? $record->displayNumber() : (string) $record->number;
    }

    protected function statusLabel(string $type, string $status): string
    {
        $enum = match ($type) {
            'lead' => LeadStatus::tryFrom($status),
            'offer' => OfferStatus::tryFrom($status),
            'order' => OrderStatus::tryFrom($status),
            default => AgreementStatus::tryFrom($status),
        };

        return $enum?->label() ?? $status;
    }
}
