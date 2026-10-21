<?php

namespace Tests\Feature\Offers;

use App\Livewire\Offers\History;
use App\Livewire\Offers\Show;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Standard document tabs on the offer detail (user decision 2026-10-20): the summary stays above
 * the tabs; Header | Items (with a counter) | Other (related documents, approvals, history).
 */
class OfferShowTabsTest extends TestCase
{
    use BuildsOffers;
    use RefreshDatabase;

    public function test_offer_detail_has_header_items_and_other_tabs(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $offer->update(['payment_terms_text' => 'Pay in 10 days', 'internal_notes' => 'Secret note']);
        $this->addLine($offer, $this->pricedItem('100.00', ['name' => 'Router RX']));

        Livewire::actingAs($rep)->test(Show::class, ['offer' => $offer])
            ->assertSet('tab', 'items')
            ->assertSeeHtml('data-document-summary')
            ->assertSeeHtml('id="doc-header"')
            ->assertSeeHtml('id="doc-other"')
            ->assertSeeHtml('data-tab-count')
            ->assertSee('Router RX')
            ->assertDontSee('Secret note')
            ->set('tab', 'header')
            ->assertSee('Pay in 10 days')
            ->assertSee('Secret note')
            ->assertDontSee('Router RX')
            ->set('tab', 'other')
            ->assertSeeLivewire(History::class);

        Livewire::actingAs($rep)->test(History::class, ['offerId' => $offer->id])
            ->assertSeeHtml('data-timeline')
            ->assertSeeHtml('data-approvals')
            ->assertSee($offer->displayNumber());
    }

    public function test_old_tab_links_open_the_matching_new_tab(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);

        foreach (['offer' => 'items', 'timeline' => 'other', 'versions' => 'other', 'approvals' => 'other', 'bogus' => 'items'] as $old => $new) {
            Livewire::actingAs($rep)->withQueryParams(['tab' => $old])->test(Show::class, ['offer' => $offer])->assertSet('tab', $new);
        }
    }

    public function test_other_tab_history_requires_view_access(): void
    {
        $owner = User::factory()->sales()->create();
        $stranger = User::factory()->sales()->create();
        $offer = $this->draftOffer($owner);

        Livewire::actingAs($stranger)->test(History::class, ['offerId' => $offer->id])->assertForbidden();
    }
}
