<?php

namespace Tests\Feature\Partners;

use App\Enums\DepartmentRole;
use App\Enums\LegalType;
use App\Livewire\Partners\Form;
use App\Livewire\Settings\ReferenceLists;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\GeoPlace;
use App\Models\Partner;
use App\Models\ReferenceItem;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec 03 section 24.7: New partner form field decisions (2026-10-19).
 */
class PartnerFormDecisionsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->manager()->create();
    }

    private function form(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->manager)->test(Form::class)->set('roles', ['customer']);
    }

    public function test_legal_type_drives_required_fields_tax_rules_and_sale_type(): void
    {
        $this->assertSame(['legal_entity', 'sole_proprietor', 'individual', 'government'], array_column(LegalType::cases(), 'value'));
        $this->assertSame('legal_entity', LegalType::Government->oneCValue());

        $this->form()->set('form.name', 'Alfa')->call('save')->assertHasErrors(['form.legal_name', 'form.tax_id']);
        $this->form()->set('form.name', 'Alfa')->set('form.legal_name', 'Alfa MChJ')->set('form.tax_id', '12345')->call('save')->assertHasErrors('form.tax_id');

        $individual = $this->form()->set('form.legal_type', 'individual')->assertSet('form.sale_type', 'retail')->assertDontSee('data-field="legal_name"', false);
        $individual->set('form.name', 'Ali Valiyev')->call('save')->assertHasNoErrors();
        $this->assertSame(LegalType::Individual, Partner::query()->where('name', 'Ali Valiyev')->sole()->legal_type);

        $this->form()->set('form.legal_type', 'individual')->set('form.name', 'Vali')->set('form.tax_id', '12345678901234')->call('save')->assertHasNoErrors();
        $this->form()->set('form.legal_type', 'government')->assertSet('form.sale_type', 'b2b');

        // Other countries: 5-20 letters or digits, no checksum.
        $this->form()->set('form.country', 'KZ')->set('form.name', 'Kaz')->set('form.legal_name', 'Kaz TOO')->set('form.tax_id', '123')->call('save')->assertHasErrors('form.tax_id');
        $this->form()->set('form.country', 'KZ')->set('form.name', 'Kaz')->set('form.legal_name', 'Kaz TOO')->set('form.tax_id', '9601AB45')->set('form.city', 'Almaty')->call('save')->assertHasNoErrors();
        $this->assertSame('Almaty', Partner::query()->where('name', 'Kaz')->sole()->city);

        // Sync with 1C needs a tax ID.
        $this->form()->set('form.legal_type', 'individual')->set('form.name', 'No tax')->set('syncWith1c', true)->call('save')->assertHasErrors('syncWith1c');
    }

    public function test_country_list_and_uz_region_city_dropdowns_switch_with_the_country(): void
    {
        $this->assertSame(Countries::PINNED, array_slice(array_keys(Countries::options()), 0, 12));
        $this->assertSame(['UZ', 'KZ', 'KG', 'TJ', 'TM', 'AF', 'RU', 'CN', 'TR', 'KR', 'DE', 'AE'], Countries::PINNED);

        $samarkand = GeoPlace::query()->where('code', 'samarkand')->sole();
        $urgut = GeoPlace::query()->where('code', 'samarkand.urgut')->sole();
        $nukus = GeoPlace::query()->where('code', 'karakalpakstan.nukus')->sole();
        $this->assertSame(14, GeoPlace::query()->where('kind', 'region')->count());

        $form = $this->form()->assertSet('form.country', 'UZ')->assertSee('data-field="region"', false)->assertDontSee('data-field="city_text"', false)
            ->set('form.region_id', (string) $samarkand->id)->assertSee($urgut->name())
            ->set('form.city_id', (string) $nukus->id)->set('form.name', 'Sam')->set('form.legal_name', 'Sam MChJ')->set('form.tax_id', '301000001')
            ->call('save')->assertHasErrors('form.city_id');

        $form->set('form.city_id', (string) $urgut->id)->set('form.postal_code', '1401')->call('save')->assertHasErrors('form.postal_code');

        // Away from UZ: dropdowns hidden and cleared, the typed city stays.
        $form->set('form.city', 'Typed city')->set('form.country', 'KZ')
            ->assertSet('form.region_id', '')->assertSet('form.city_id', '')->assertSet('form.city', 'Typed city')
            ->assertDontSee('data-field="region"', false)->assertSee('data-field="city_text"', false)
            ->set('form.country', 'UZ')->assertSee('data-field="region"', false);

        $form->set('form.region_id', (string) $samarkand->id)->set('form.city_id', (string) $urgut->id)->set('form.postal_code', '140100')->call('save')->assertHasNoErrors();
        $partner = Partner::query()->where('tax_id', '301000001')->sole();
        $this->assertSame([$samarkand->id, $urgut->id, 'Urgut'], [$partner->region_id, $partner->city_id, $partner->city]);
    }

    public function test_owner_list_follows_the_roles_and_changing_it_needs_the_assign_right(): void
    {
        $sales = User::factory()->sales()->create(['name' => 'Sales Person']);
        $stock = User::factory()->stockManager()->create(['name' => 'Stock Person']);
        $purchaser = User::factory()->staff()->inDepartment('purchase', DepartmentRole::Head)->create(['name' => 'Purchase Person']);

        $form = $this->form();
        $owners = $form->instance()->owners->pluck('name')->all();
        $this->assertContains('Sales Person', $owners);
        $this->assertNotContains('Stock Person', $owners);
        $this->assertNotContains('Purchase Person', $owners);
        $form->set('roles', ['supplier']);
        $this->assertContains('Purchase Person', $form->instance()->owners->pluck('name')->all());
        $this->assertNotNull($stock);

        Livewire::actingAs($sales)->test(Form::class)->assertSet('form.owner_id', (string) $sales->id)->assertSee('data-role-option="customer"', false)->assertDontSee('data-role-option="supplier"', false)
            ->set('form.owner_id', (string) $this->manager->id)->set('form.name', 'X')->set('form.legal_name', 'X MChJ')->set('form.tax_id', '301000002')
            ->call('save')->assertHasErrors('form.owner_id');
    }

    public function test_lists_document_language_currency_category_and_typed_position(): void
    {
        $wholesale = ReferenceItem::query()->where('list', 'industry')->where('code', 'wholesale')->sole();
        $forwarder = ReferenceItem::query()->where('list', 'supplier_category')->where('code', 'forwarder')->sole();

        $form = Livewire::actingAs($this->manager)->test(Form::class)->set('roles', ['customer', 'carrier'])
            ->assertSet('form.preferred_locale', 'uz')->assertSet('form.currency', 'UZS')->assertSet('form.payment_terms_days', '0')
            ->assertDontSee('<option value="">'.__('customers.no_preference').'</option>', false);
        $this->assertSame(['UZS', 'USD', 'EUR', 'RUB', 'CNY'], $form->instance()->currencies);

        $form->set('form.name', 'Mix')->set('form.legal_name', 'Mix MChJ')->set('form.tax_id', '301000003')->set('form.industry_id', (string) $wholesale->id)
            ->set('form.currency', 'USD')->set('form.supplier_category_id', (string) $forwarder->id)->set('form.website', 'mix.uz')->set('form.email', 'INFO@MIX.UZ')
            ->set('form.contact_first_name', 'Aziz')->set('form.contact_position', 'Warehouse supervisor')->call('save')->assertHasNoErrors();

        $partner = Partner::query()->where('tax_id', '301000003')->sole();
        $this->assertSame([$wholesale->id, 'USD', 'https://mix.uz', 'info@mix.uz', 0], [$partner->industry_id, $partner->currency, $partner->website, $partner->email, Customer::query()->find($partner->id)->payment_terms_days]);
        $this->assertSame($forwarder->id, Counterparty::query()->where('partner_id', $partner->id)->value('supplier_category_id'));
        $this->assertTrue(ReferenceItem::query()->where('list', 'contact_position')->where('source', 'user')->whereJsonContains('names->en', 'Warehouse supervisor')->exists(), 'Typed positions become suggestions.');
    }

    public function test_reference_lists_page_manages_entries_and_never_deletes_used_ones(): void
    {
        Livewire::actingAs(User::factory()->sales()->create())->test(ReferenceLists::class)->assertForbidden();

        $page = Livewire::actingAs($this->manager)->test(ReferenceLists::class)->assertSee(__('reference.lists.industry'))
            ->set('new.en', 'Mining')->set('new.ru', 'Добыча')->set('new.uz', 'Kon sanoati')->call('add')->assertHasNoErrors();
        $mining = ReferenceItem::query()->where('list', 'industry')->where('code', 'mining')->sole();

        $page->set('rows.'.$mining->id.'.en', 'Mining & quarrying')->call('saveRow', $mining->id);
        $this->assertSame('Mining & quarrying', $mining->fresh()->names['en']);
        $page->call('toggle', $mining->id);
        $this->assertFalse($mining->fresh()->is_active);

        $used = ReferenceItem::query()->where('list', 'industry')->where('code', 'retail')->sole();
        Partner::query()->create(['name' => 'Shop', 'legal_type' => 'legal_entity', 'country' => 'UZ', 'currency' => 'UZS', 'owner_id' => $this->manager->id, 'origin' => 'crm', 'industry_id' => $used->id]);
        $page->call('delete', $used->id)->assertHasErrors('row-'.$used->id);
        $this->assertNotNull($used->fresh());
        $page->call('delete', $mining->id);
        $this->assertNull($mining->fresh());

        $page->set('list', 'currency')->set('new.code', 'kzt')->set('new.en', 'Tenge')->set('new.ru', 'Тенге')->set('new.uz', 'Tenge')->call('add')->assertHasNoErrors();
        $this->assertContains('KZT', Livewire::actingAs($this->manager)->test(Form::class)->instance()->currencies);
    }

    public function test_free_text_industries_and_cities_migrate_without_losing_anything(): void
    {
        // Replays archived (pre-squash) migrations written for SQLite dev data; MySQL runs skip it.
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Archived data migration replay runs on SQLite only.');
        }

        $migration = require database_path('migrations_archive_pre_squash/2026_10_19_114835_create_reference_lists_and_geo_places.php');
        $migration->down();
        $base = ['legal_type' => 'legal_entity', 'currency' => 'UZS', 'owner_id' => $this->manager->id, 'origin' => 'crm', 'uuid' => '', 'name_normalized' => 'x', 'created_at' => now(), 'updated_at' => now()];
        DB::table('partners')->insert([
            [...$base, 'uuid' => (string) Str::uuid(), 'name' => 'A', 'country' => 'UZ', 'industry' => 'wholesale', 'city' => 'Samarkand'],
            [...$base, 'uuid' => (string) Str::uuid(), 'name' => 'B', 'country' => 'UZ', 'industry' => 'Fish farming', 'city' => 'Nowhere'],
            [...$base, 'uuid' => (string) Str::uuid(), 'name' => 'C', 'country' => 'TR', 'industry' => null, 'city' => 'Istanbul'],
        ]);
        $migration->up();

        $a = Partner::query()->where('name', 'A')->sole();
        $b = Partner::query()->where('name', 'B')->sole();
        $c = Partner::query()->where('name', 'C')->sole();
        $this->assertSame('wholesale', $a->industryItem?->code);
        $this->assertSame('samarkand.samarkand', $a->cityPlace?->code);
        $legacy = $b->industryItem;
        $this->assertSame(['legacy', false, 'Fish farming'], [$legacy?->source, $legacy?->is_active, $legacy?->names['en']]);
        $this->assertSame(['Nowhere', null], [$b->city, $b->city_id], 'Unmatched cities stay as text.');
        $this->assertNull($c->city_id);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
