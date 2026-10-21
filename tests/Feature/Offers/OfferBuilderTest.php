<?php

namespace Tests\Feature\Offers;

use App\Actions\Offers\SubmitOfferForApproval;
use App\Actions\Offers\UpdateOfferLine;
use App\Enums\DepartmentRole;
use App\Enums\OfferStatus;
use App\Enums\OptionGroupKind;
use App\Enums\StockBand;
use App\Livewire\Offers\Builder;
use App\Livewire\Offers\Create;
use App\Livewire\Offers\ItemPicker;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemAlternative;
use App\Models\ItemStock;
use App\Models\Offer;
use App\Models\Unit;
use App\Models\User;
use App\Services\Offers\DiscountRules;
use App\Services\Offers\Exceptions\OfferActionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 05 increments 05-1..05-3: create, numbering, catalog search, price snapshot, groups,
 * recommended, totals persisted server-side, serial and accounting-code snapshot.
 */
class OfferBuilderTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    public function test_create_screen_starts_a_numbered_draft_for_the_customer(): void
    {
        $rep = User::factory()->sales()->create();
        $customer = Customer::factory()->ownedBy($rep)->create(['currency' => 'UZS']);

        Livewire::actingAs($rep)->test(Create::class)
            ->set('customerSearch', $customer->name)
            ->assertSee($customer->name)
            ->call('selectCustomer', $customer->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $offer = Offer::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^OF-\d{4}-00001$/', $offer->number);
        $this->assertSame(OfferStatus::Draft, $offer->status);
        $this->assertSame($rep->id, $offer->owner_id);
        $this->assertNull($offer->lead_id);
        $this->assertSame(now(config('offers.timezone'))->addDays(14)->toDateString(), $offer->valid_until->toDateString());
        $this->assertDatabaseHas('status_transitions', ['subject_type' => 'offer', 'subject_id' => $offer->id, 'from_status' => null, 'to_status' => 'draft', 'user_id' => $rep->id]);

        $second = $this->draftOffer($rep, $customer);
        $this->assertStringEndsWith('-00002', $second->number, 'Numbers are sequential and never reused.');
    }

    public function test_line_snapshots_the_item_sale_price_cost_basis_and_item_fields(): void
    {
        $rep = User::factory()->sales()->create();
        $item = $this->pricedItem('200.00', ['accountant_code' => 'ACC-77', 'class_code' => '08517620001000000', 'package_code' => '1503778', 'name' => 'Router', 'purchase_price_minor' => 15000, 'purchase_currency' => 'UZS']);
        $offer = $this->draftOffer($rep);

        $line = $this->addLine($offer, $item, '3');

        $this->assertSame([20000, 20000], [$line->unit_price_minor, $line->list_unit_price_minor]);
        $this->assertSame('item_sale_price', $line->price_source);
        $this->assertSame(15000, $line->cost_price_minor, 'No stock cost reported: the purchase price is the cost basis.');
        $this->assertSame(['ACC-77', '08517620001000000', '1503778'], [$line->accountant_code, $line->class_code, $line->package_code]);
        $this->assertSame(['Router', $item->sku, $item->external_id], [$line->name, $line->sku, $line->item_external_id]);
        $this->assertSame(StockBand::InStock, $line->stock_band);
        $this->assertSame([60000, 7200, 67200], [$line->line_net_minor, $line->line_vat_minor, $line->line_gross_minor]);

        // Later catalog changes never alter the snapshot.
        $item->update(['name' => 'Renamed', 'accountant_code' => 'NEW', 'sale_price_minor' => 1]);
        $this->assertSame(['Router', 'ACC-77', 20000], [$line->fresh()->name, $line->fresh()->accountant_code, $line->fresh()->unit_price_minor]);
    }

    public function test_gross_item_prices_are_converted_once_to_the_offer_basis(): void
    {
        $rep = User::factory()->sales()->create();
        $item = $this->pricedItem('112.00', ['prices_include_vat' => true]);
        $offer = $this->draftOffer($rep);

        $this->assertFalse($offer->prices_include_vat);
        $this->assertSame(10000, $this->addLine($offer, $item)->unit_price_minor, '112.00 incl. 12% VAT = 100.00 net.');

        $usd = $this->pricedItem('10.00', ['sale_currency' => 'USD']);

        try {
            $this->addLine($offer, $usd);
            $this->fail('A price in another currency must be refused (no FX).');
        } catch (OfferActionException $exception) {
            $this->assertSame('no_price', $exception->key);
        }
    }

    /** Rev. 4: the cost basis drives margin and the below-cost approval rule. */
    public function test_a_price_below_cost_requires_manager_approval(): void
    {
        $rep = User::factory()->sales()->create();
        $head = User::factory()->sales()->inDepartment('sales', DepartmentRole::Head)->create();
        $manager = User::factory()->manager()->create();
        $item = $this->pricedItem('100.00');
        ItemStock::query()->where('item_id', $item->id)->update(['cost_minor' => 900000, 'cost_currency' => 'UZS']); // 100 pcs at 90.00
        $offer = $this->draftOffer($rep);
        $line = $this->addLine($offer, $item, '2');

        $this->assertSame(9000, $line->cost_price_minor, 'Stock cost price wins over the purchase price.');
        $this->assertFalse($line->isBelowCost());
        $this->assertFalse(app(DiscountRules::class)->requiresApproval($offer->fresh()));

        // 105.00 > 100.00 list: no discount at all, but still... (sanity) then 85.00: 15% would also exceed 5%.
        app(UpdateOfferLine::class)->handle($line, $rep, ['discount_bp' => 300]);
        $offer->refresh();
        $this->assertFalse($offer->lines->first()->isBelowCost(), '97.00 is above the 90.00 cost.');

        app(UpdateOfferLine::class)->handle($line->fresh(), $rep, ['unit_price_minor' => 8800, 'discount_bp' => 0]);
        $offer->refresh();
        $this->assertTrue($offer->lines->first()->isBelowCost());
        $this->assertTrue(app(DiscountRules::class)->requiresApproval($offer));

        Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])->assertSee('data-below-cost', false)->assertSee(__('offers.builder.below_cost_badge'));

        app(SubmitOfferForApproval::class)->handle($offer, $rep);
        $offer->refresh();
        $this->assertTrue($offer->approvals()->first()->reasons[0]['below_cost']);
        $this->assertFalse($head->can('approve', $offer), 'Within the head\'s discount limit, but below cost needs a manager.');
        $this->assertTrue($manager->can('approve', $offer));
    }

    public function test_items_without_price_or_not_sellable_cannot_be_added(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $unpriced = Item::factory()->create();

        try {
            $this->addLine($offer, $unpriced);
            $this->fail('No price must be refused.');
        } catch (OfferActionException $exception) {
            $this->assertSame('no_price', $exception->key);
        }

        $inactive = $this->pricedItem('10.00', ['is_active' => false]);
        $this->expectException(OfferActionException::class);
        $this->addLine($offer, $inactive);
    }

    public function test_serialized_items_need_whole_quantities_and_show_the_delivery_note(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $kg = Unit::factory()->create(['precision' => 3]);
        $serial = $this->pricedItem('100.00', ['is_serialized' => true, 'unit_id' => $kg->id]);

        try {
            $this->addLine($offer, $serial, '1.5');
            $this->fail('Fractional quantity of a serialized item must be refused.');
        } catch (OfferActionException $exception) {
            $this->assertSame('quantity_serialized', $exception->key);
        }

        $line = $this->addLine($offer, $serial, '2');
        $this->assertTrue($line->is_serialized);

        Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])
            ->assertSee(__('offers.builder.serial_note'))
            ->set("lineInputs.{$line->id}.quantity", '2.5')
            ->assertHasErrors("lineInputs.{$line->id}.quantity");

        $this->assertSame('2.000', (string) $line->fresh()->quantity);
    }

    public function test_builder_adds_fixed_items_groups_and_options_with_live_totals(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $router = $this->pricedItem('1000.00', ['name' => 'Router RX', 'accountant_code' => 'ACC-RX', 'class_code' => '08517620001000000']);
        $a = $this->pricedItem('500.00', ['name' => 'Switch A']);
        $b = $this->pricedItem('800.00', ['name' => 'Switch B']);

        // The catalog search is its own component (typing re-renders only the picker).
        Livewire::actingAs($rep)->test(ItemPicker::class, ['offerId' => $offer->id])
            ->set('itemSearch', 'ACC-RX')
            ->assertSee('Router RX')
            ->assertSee('08517620001000000')
            ->call('addItem', $router->id)
            ->assertHasNoErrors()
            ->assertSet('itemSearch', '')
            ->assertDispatched('offer-line-added')
            ->assertDispatched('item-picker-focus');

        $component = Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])
            ->call('addGroup', 'alternative');

        $group = $offer->groups()->firstOrFail();
        $this->assertSame(OptionGroupKind::Alternative, $group->kind);

        Livewire::actingAs($rep)->test(ItemPicker::class, ['offerId' => $offer->id, 'targetGroupId' => $group->id])
            ->set('itemSearch', 'Switch')
            ->call('addItem', $a->id)
            ->call('addItem', $b->id);
        $component->call('lineAdded');

        $optionB = $offer->lines()->where('item_id', $b->id)->firstOrFail();
        $this->assertSame('B', $optionB->option_label);

        $component->call('setRecommended', $optionB->id)
            ->assertSee(__('documents.offer.recommended'));

        $offer->refresh();
        $this->assertSame(112000, $offer->fixed_gross_minor);
        $this->assertSame([168000, 201600], [$offer->range_min_gross_minor, $offer->range_max_gross_minor]);
        $this->assertSame(201600, $offer->recommended_gross_minor);
        $this->assertNotNull($offer->content_hash);

        // Editing a line recomputes server-side; discount and price come from the inputs.
        $fixed = $offer->lines()->whereNull('option_group_id')->firstOrFail();
        $component->set("lineInputs.{$fixed->id}.discount", '10')->assertHasNoErrors();
        $this->assertSame(1000, $fixed->fresh()->discount_bp);
        $this->assertSame(100800, $offer->fresh()->fixed_gross_minor);

        $component->set("lineInputs.{$fixed->id}.unit_price", 'abc')->assertHasErrors("lineInputs.{$fixed->id}.unit_price");
        $component->call('addGroup', 'optional')->assertSee(__('offers.builder.optional_heading'));
        $component->set('tab', 'preview')->assertSee(__('documents.offer.alternative_heading'))->assertSee(__('documents.offer.not_added_note'));
    }

    /** Bug fix and layout 2026-10-20: the search lives in its own component, two columns. */
    public function test_search_is_isolated_from_the_builder(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $this->pricedItem('1000.00', ['name' => 'Router RX']);

        $builder = Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])
            ->assertSeeLivewire(ItemPicker::class)
            ->set('payment_terms_text', 'Unsaved terms');

        Livewire::actingAs($rep)->test(ItemPicker::class, ['offerId' => $offer->id])
            ->assertSeeHtml('@keydown.enter.prevent')
            ->set('itemSearch', 'Router')
            ->assertSee('Router RX');

        $builder->assertSet('payment_terms_text', 'Unsaved terms');
        $this->assertNull($offer->fresh()->payment_terms_text, 'Searching saves nothing.');
    }

    /** Standard document tabs (2026-10-20): Header | Items | Customer preview | Other. */
    public function test_builder_tabs_keep_unsaved_header_edits_and_mark_them(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);

        $builder = Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])
            ->assertSet('tab', 'items')
            ->assertSeeHtml('data-document-summary')
            ->assertSeeHtml('role="tablist"')
            ->assertSeeHtml('id="doc-header"')
            ->assertSeeHtml('id="doc-preview"')
            ->assertSeeHtml('id="doc-other"')
            ->assertDontSeeHtml('data-tab-marker')
            ->set('tab', 'header')
            ->assertSeeHtml('data-offer-header')
            ->set('payment_terms_text', 'Unsaved terms')
            ->assertSeeHtml('data-tab-marker')
            ->set('tab', 'items')
            ->assertSeeHtml('data-header-unsaved')
            ->set('tab', 'header')
            ->assertSet('payment_terms_text', 'Unsaved terms')
            ->set('tab', 'other')
            ->assertSeeHtml('data-timeline')
            ->set('tab', 'nonsense')
            ->assertSet('tab', 'items');

        $this->assertNull($offer->fresh()->payment_terms_text, 'Switching tabs saves nothing.');

        $builder->set('tab', 'header')->call('saveHeader')->assertHasNoErrors()->assertDontSeeHtml('data-tab-marker');
        $this->assertSame('Unsaved terms', $offer->fresh()->payment_terms_text);

        // Old links (?tab=edit) open the Items tab.
        Livewire::actingAs($rep)->withQueryParams(['tab' => 'edit'])->test(Builder::class, ['offer' => $offer])->assertSet('tab', 'items');
    }

    public function test_adding_an_item_keeps_unsaved_edits_of_other_lines(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $line = $this->addLine($offer, $this->pricedItem('100.00'));

        $builder = Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])
            ->set('lineInputs.'.$line->id.'.quantity', '7');

        $this->addLine($offer, $this->pricedItem('200.00'));
        $builder->call('lineAdded')
            ->assertSet('lineInputs.'.$line->id.'.quantity', '7')
            ->assertCount('lineInputs', 2);
    }

    /** AC-9 */
    public function test_totals_cannot_be_tampered_from_the_client(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $this->addLine($offer, $this->pricedItem('1000.00'));

        $component = Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $component->set('offerId', 999);
    }

    /** AC-5 */
    public function test_offer_alternatives_converts_a_fixed_line_and_ungroup_restores_it(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $line = $this->addLine($offer, $this->pricedItem('300.00'));
        $stableKey = $line->stable_key;

        Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])->call('convertToAlternatives', $line->id)->assertHasNoErrors();

        $line->refresh();
        $group = $offer->groups()->firstOrFail();
        $this->assertSame([$group->id, 'A', $stableKey, 30000], [$line->option_group_id, $line->option_label, $line->stable_key, $line->unit_price_minor]);

        Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])->call('ungroup', $group->id);

        $this->assertNull($line->fresh()->option_group_id);
        $this->assertSame(0, $offer->groups()->count());
    }

    /** V4 / V7 / max options */
    public function test_group_rules_duplicate_items_and_max_six_options(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $group = $this->addGroup($offer, OptionGroupKind::Alternative);
        $items = collect(range(1, 7))->map(fn (int $i) => $this->pricedItem($i.'00.00'));

        $this->addLine($offer, $items[0], '1', $group);

        try {
            $this->addLine($offer, $items[0], '1', $group);
            $this->fail('Duplicate item in a group must be refused.');
        } catch (OfferActionException $exception) {
            $this->assertSame('duplicate_item', $exception->key);
        }

        foreach ($items->slice(1, 5) as $item) {
            $this->addLine($offer, $item, '1', $group);
        }

        $this->assertSame('F', $offer->lines()->where('option_group_id', $group->id)->reorder()->orderByDesc('position')->value('option_label'));
        $this->expectException(OfferActionException::class);
        $this->addLine($offer, $items[6], '1', $group);
    }

    public function test_linked_analogues_are_suggested_first_inside_an_alternative_group(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $original = $this->pricedItem('100.00', ['name' => 'Original pump']);
        $analogue = $this->pricedItem('90.00', ['name' => 'Analogue pump']);
        ItemAlternative::factory()->create(['item_id' => $original->id, 'alternative_item_id' => $analogue->id]);
        $group = $this->addGroup($offer, OptionGroupKind::Alternative);
        $this->addLine($offer, $original, '1', $group);

        Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])->call('targetGroup', $group->id)->assertSet('targetGroupId', $group->id);
        Livewire::actingAs($rep)->test(ItemPicker::class, ['offerId' => $offer->id, 'targetGroupId' => $group->id])
            ->assertSee('Analogue pump')
            ->assertSee(__('offers.builder.linked_analogue', ['relation' => __('offers.relations.analogue')]));
    }

    public function test_sent_offers_cannot_be_edited_in_the_builder(): void
    {
        $rep = User::factory()->sales()->create();
        $offer = Offer::factory()->ownedBy($rep)->status(OfferStatus::Sent)->create();

        $this->actingAs($rep)->get(route('offers.edit', $offer))->assertForbidden();
    }
}
