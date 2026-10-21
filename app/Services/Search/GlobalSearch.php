<?php

namespace App\Services\Search;

use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Partner;
use App\Models\User;
use App\Support\UzbekIdentifiers;

/**
 * Global search (roadmap step 5) over leads, customers, offers, orders, items and serial numbers.
 * Each group appears only when the user may list that module and returns only records the user may
 * see (the same visibility scopes and policies as the lists); at most PER_GROUP hits per group.
 */
class GlobalSearch
{
    public const PER_GROUP = 5;

    public const MIN_LENGTH = 2;

    /**
     * @return array<string, list<array{title: string, subtitle: string, url: string}>>
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        $digits = (string) preg_replace('/\D/', '', $term);
        $phone = strlen($digits) >= 5 ? UzbekIdentifiers::normalizePhone($term) : null;
        $groups = [];

        if ($user->can('viewAny', Lead::class)) {
            $groups['leads'] = Lead::query()->visibleTo($user)->with('customer:id,name')
                ->where(fn ($q) => $q->where('number', 'like', $like)->orWhere('company_name', 'like', $like)->orWhere('contact_name', 'like', $like)
                    ->orWhere('contact_email', 'like', mb_strtolower($like))->when($phone, fn ($p) => $p->orWhere('contact_phone', $phone))
                    ->when(strlen($digits) >= 5, fn ($p) => $p->orWhere('tax_id', 'like', '%'.$digits.'%')))
                ->latest('id')->limit(self::PER_GROUP)->get()
                ->map(fn (Lead $lead): array => ['title' => $lead->number.' · '.$lead->companyLabel(), 'subtitle' => $lead->status->label(), 'url' => route('leads.show', $lead)])->all();
        }

        if ($user->can('viewAny', Customer::class)) {
            $groups['customers'] = Customer::query()->visibleTo($user)
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('legal_name', 'like', $like)->orWhere('email', 'like', mb_strtolower($like))
                    ->when(strlen($digits) >= 5, fn ($p) => $p->orWhere('tax_id', 'like', '%'.$digits.'%')->orWhere('phone', 'like', '%'.$digits.'%')))
                ->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Partner $customer): array => ['title' => $customer->name, 'subtitle' => collect([$customer->tax_id, $customer->city])->filter()->join(' · '), 'url' => route('customers.show', $customer)])->all();
        }

        if ($user->can('viewAny', Offer::class)) {
            $groups['offers'] = Offer::query()->visibleTo($user)->with('customer:id,name')
                ->where(fn ($q) => $q->where('number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)))
                ->latest('id')->limit(self::PER_GROUP)->get()
                ->map(fn (Offer $offer): array => ['title' => $offer->displayNumber().' · '.$offer->customer?->name, 'subtitle' => $offer->status->label(), 'url' => route('offers.show', $offer)])->all();
        }

        if ($user->can('viewAny', Order::class)) {
            $groups['orders'] = Order::query()->visibleTo($user)->with('customer:id,name')
                ->where(fn ($q) => $q->where('number', 'like', $like)->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)))
                ->latest('id')->limit(self::PER_GROUP)->get()
                ->map(fn (Order $order): array => ['title' => $order->number.' · '.$order->customer?->name, 'subtitle' => $order->status->label(), 'url' => route('orders.show', $order)])->all();
        }

        if ($user->can('viewAny', Item::class)) {
            $groups['items'] = Item::query()
                ->where(fn ($q) => $q->where('sku', 'like', $like)->orWhere('name', 'like', $like)->orWhere('article', 'like', $like))
                ->orderBy('name')->limit(self::PER_GROUP)->get()
                ->map(fn (Item $item): array => ['title' => $item->name, 'subtitle' => (string) $item->sku, 'url' => route('items.show', $item)])->all();
        }

        if ($user->can('viewAny', ItemSerial::class)) {
            $normalized = mb_strtoupper((string) preg_replace('/\s+/', '', $term));
            $groups['serials'] = ItemSerial::query()->with(['item:id,name', 'soldOrderLine.order:id,owner_id'])
                ->where('serial_normalized', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $normalized).'%')
                ->orderBy('serial_normalized')->limit(self::PER_GROUP * 4)->get()
                ->filter(fn (ItemSerial $serial): bool => $user->can('view', $serial))->take(self::PER_GROUP)
                ->map(fn (ItemSerial $serial): array => ['title' => (string) $serial->serial_normalized, 'subtitle' => (string) $serial->item?->name.' · '.$serial->status->label(), 'url' => route('serials.show', $serial)])->values()->all();
        }

        return array_filter($groups, fn (array $hits): bool => $hits !== []);
    }
}
