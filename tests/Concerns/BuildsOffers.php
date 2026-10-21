<?php

namespace Tests\Concerns;

use App\Actions\Offers\AddOfferLine;
use App\Actions\Offers\CreateOffer;
use App\Actions\Offers\ManageOptionGroups;
use App\Actions\Offers\UpdateOfferLine;
use App\Enums\OptionGroupKind;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemStock;
use App\Models\Offer;
use App\Models\OfferLine;
use App\Models\OfferOptionGroup;
use App\Models\User;

/**
 * Builds offers through the real Actions (price resolution, snapshots, server-side totals).
 * Items carry one net sale price in UZS (VAT 12%) unless stated otherwise (rev. 4).
 */
trait BuildsOffers
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function pricedItem(string $price, array $attributes = [], ?string $stock = '100'): Item
    {
        $item = Item::factory()->priced($price)->create(['tax_rate_bp' => 1200, ...$attributes]);

        if ($stock !== null) {
            ItemStock::factory()->create(['item_id' => $item->id, 'quantity' => $stock, 'reserved' => '0']);
        }

        return $item;
    }

    protected function draftOffer(User $owner, ?Customer $customer = null): Offer
    {
        $customer ??= Customer::factory()->ownedBy($owner)->create(['currency' => 'UZS']);

        return app(CreateOffer::class)->handle($owner, $customer);
    }

    protected function addLine(Offer $offer, Item $item, string $quantity = '1', ?OfferOptionGroup $group = null, ?User $user = null): OfferLine
    {
        return app(AddOfferLine::class)->handle($offer->refresh(), $item, $quantity, $user ?? $offer->owner, $group);
    }

    protected function addGroup(Offer $offer, OptionGroupKind $kind, string $title = 'Group', ?User $user = null): OfferOptionGroup
    {
        return app(ManageOptionGroups::class)->add($offer->refresh(), $kind, $title, $user ?? $offer->owner);
    }

    /**
     * Spec 05 AC-6 fixture: fixed line 1 000.00 net; alternative group A 500.00, B 800.00
     * (recommended), C 650.00; optional group X 100.00, Y 50.00. VAT 12%, net basis.
     *
     * @return array{offer: Offer, alternative: OfferOptionGroup, optional: OfferOptionGroup, lines: array<string, OfferLine>}
     */
    protected function fixtureOffer(User $owner, ?Customer $customer = null): array
    {
        $offer = $this->draftOffer($owner, $customer);
        $lines = ['fixed' => $this->addLine($offer, $this->pricedItem('1000.00', ['name' => 'Fixed router']))];

        $alternative = $this->addGroup($offer, OptionGroupKind::Alternative, 'Air compressor');
        $lines['A'] = $this->addLine($offer, $this->pricedItem('500.00', ['name' => 'Compressor budget']), '1', $alternative);
        $lines['B'] = $this->addLine($offer, $this->pricedItem('800.00', ['name' => 'Compressor premium']), '1', $alternative);
        $lines['C'] = $this->addLine($offer, $this->pricedItem('650.00', ['name' => 'Compressor analogue']), '1', $alternative);
        app(UpdateOfferLine::class)->handle($lines['B'], $owner, ['is_recommended' => true, 'comparison_notes' => 'IE3 motor']);
        app(UpdateOfferLine::class)->handle($lines['A'], $owner, ['alternative_reason' => 'cheaper_analogue']);
        app(UpdateOfferLine::class)->handle($lines['C'], $owner, ['alternative_reason' => 'faster_delivery', 'internal_note' => 'SECRET-MARGIN-NOTE']);

        $optional = $this->addGroup($offer, OptionGroupKind::Optional, 'Add-ons');
        $lines['X'] = $this->addLine($offer, $this->pricedItem('100.00', ['name' => 'Installation'], null), '1', $optional);
        $lines['Y'] = $this->addLine($offer, $this->pricedItem('50.00', ['name' => 'Extended warranty'], null), '1', $optional);

        foreach ($lines as $key => $line) {
            $lines[$key] = $line->refresh();
        }

        return ['offer' => $offer->refresh(), 'alternative' => $alternative->refresh(), 'optional' => $optional->refresh(), 'lines' => $lines];
    }
}
