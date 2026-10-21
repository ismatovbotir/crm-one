<?php

namespace Tests\Feature\Catalog;

use App\Enums\DepartmentRole;
use App\Enums\OptionGroupKind;
use App\Livewire\Catalog\ItemAnalogues;
use App\Livewire\Catalog\Items\Show;
use App\Livewire\Offers\Builder;
use App\Livewire\Offers\ItemPicker;
use App\Models\Item;
use App\Models\ItemAlternative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsOffers;
use Tests\TestCase;

/**
 * Spec 01 section 17: item page tabs (visibility per role, default and URL tab, lazy loading,
 * keyboard attributes), the Analogues tab (add/remove, two-way, used by) and the offer builder
 * suggesting two-way analogues.
 */
class ItemPageTabsTest extends TestCase
{
    use BuildsOffers, RefreshDatabase;

    public function test_tabs_default_url_selection_visibility_and_accessibility_attributes(): void
    {
        $item = Item::factory()->create(['name' => 'Router RX']);
        $sales = User::factory()->sales()->create();

        $page = Livewire::actingAs($sales)->test(Show::class, ['item' => $item])->assertSet('tab', 'overview');
        $html = $page->html();
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('aria-controls="item-panel-analogues"', $html);
        $this->assertStringContainsString('id="item-panel-analogues"', $html, 'Every tab points to a real panel.');
        $this->assertStringContainsString('aria-selected="true"', $html);
        $this->assertStringContainsString('data-tab="usage"', $html);

        $this->actingAs($sales)->get(route('items.show', ['item' => $item, 'tab' => 'analogues']))->assertOk()->assertSee('data-item-analogues', false);
        Livewire::actingAs($sales)->withQueryParams(['tab' => 'nonsense'])->test(Show::class, ['item' => $item])->assertSet('tab', 'overview');

        $clerk = User::factory()->staff()->inDepartment('support', DepartmentRole::Viewer)->create();
        $tabs = Livewire::actingAs($clerk)->test(Show::class, ['item' => $item])->instance()->tabs();
        $this->assertArrayNotHasKey('usage', $tabs, 'Hidden when the user cannot see offers, orders or leads.');
    }

    public function test_heavy_tabs_load_only_when_opened(): void
    {
        $item = Item::factory()->create();
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });

        $page = Livewire::actingAs(User::factory()->manager()->create())->test(Show::class, ['item' => $item]);
        $joined = implode("\n", $sql);
        // Bare table name: SQLite quotes with "", MySQL with backticks.
        $this->assertStringNotContainsString('offer_lines', $joined);
        $this->assertStringNotContainsString('from "activity_log"', $joined);
        $this->assertStringNotContainsString('order by "serial_normalized"', $joined);

        $sql = [];
        $page->set('tab', 'usage');
        // Bare table name: SQLite quotes with "", MySQL with backticks.
        $this->assertStringContainsString('offer_lines', implode("\n", $sql));
    }

    public function test_analogues_tab_manages_links_and_the_builder_suggests_two_way_ones(): void
    {
        $manager = User::factory()->manager()->create();
        $original = $this->pricedItem('100.00', ['name' => 'Original pump']);
        $analogue = $this->pricedItem('90.00', ['name' => 'Analogue pump']);
        $upgrade = $this->pricedItem('150.00', ['name' => 'Upgrade pump']);

        Livewire::actingAs(User::factory()->sales()->create())->test(ItemAnalogues::class, ['itemId' => $original->id])->call('add')->assertForbidden();

        $tab = Livewire::actingAs($manager)->test(ItemAnalogues::class, ['itemId' => $original->id])
            ->set('search', 'Analog')->assertSee('Analogue pump')
            ->set('candidateId', $analogue->id)->set('twoWay', true)->set('note', 'Same flow')->call('add')->assertHasNoErrors()
            ->set('search', 'Upgra')->set('candidateId', $upgrade->id)->set('relation', 'upgrade')->call('add')->assertHasNoErrors()
            ->assertSee('Analogue pump')->assertSee($analogue->salePrice()->format());
        $tab->set('search', 'Analog')->set('candidateId', $analogue->id)->call('add')->assertHasErrors('candidateId');

        Livewire::actingAs($manager)->test(ItemAnalogues::class, ['itemId' => $analogue->id])->assertSee('Original pump');
        Livewire::actingAs($manager)->test(ItemAnalogues::class, ['itemId' => $upgrade->id])->assertSee(__('catalog.analogues.used_by'))->assertSee('Original pump');

        // Builder: an alternative group with the analogue suggests the original through the two-way link.
        $rep = User::factory()->sales()->create();
        $offer = $this->draftOffer($rep);
        $group = $this->addGroup($offer, OptionGroupKind::Alternative);
        $this->addLine($offer, $analogue, '1', $group);
        Livewire::actingAs($rep)->test(Builder::class, ['offer' => $offer])->call('targetGroup', $group->id)->assertSet('targetGroupId', $group->id);
        Livewire::actingAs($rep)->test(ItemPicker::class, ['offerId' => $offer->id, 'targetGroupId' => $group->id])
            ->assertSee('Original pump')->assertSee(__('offers.builder.linked_analogue', ['relation' => __('offers.relations.analogue')]));

        $link = ItemAlternative::query()->where('item_id', $original->id)->where('alternative_item_id', $upgrade->id)->sole();
        $this->actingAs($manager);
        $tab->call('remove', $link->id);
        $this->assertNull($link->fresh());
    }
}
